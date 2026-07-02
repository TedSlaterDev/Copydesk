jQuery(function($){

  /* ---- Rescan a single post, then live-poll for the result ----
   * The scan is one AI call (what you already trigger). Polling just reads the
   * stored result from WordPress — it never calls the provider, so it's free. */
  function eqaBadge(ctx, d){
    var isPriced = (d.status === 'issues' || d.status === 'clean');
    if(ctx === 'metabox'){
      if(d.status === 'error')   return '<span class="ogm-eqa-badge ogm-eqa-badge-bad">Error</span>';
      if(!isPriced)              return '<span class="ogm-eqa-badge-scanning">Not scanned</span>';
      return d.issues > 0
        ? '<span class="ogm-eqa-badge ogm-eqa-badge-bad">Issues: ' + d.issues + '</span>'
        : '<span class="ogm-eqa-badge ogm-eqa-badge-good">Clean</span>';
    }
    // Posts-list / Reports-table contexts.
    if(d.status === 'error') return '<span class="ogm-eqa-badge ogm-eqa-badge-bad" title="' + (d.error || '') + '">!</span>';
    if(!isPriced)            return '<span class="ogm-eqa-badge-none">—</span>';
    return d.issues > 0
      ? '<span class="ogm-eqa-badge ogm-eqa-badge-bad">' + d.issues + '</span>'
      : '<span class="ogm-eqa-badge ogm-eqa-badge-good">0</span>';
  }

  function eqaSetBadge($wrap, html){
    var $b = $wrap.find('.ogm-eqa-badge, .ogm-eqa-badge-scanning, .ogm-eqa-badge-none').first();
    if($b.length){ $b.replaceWith(html); return; }
    var $bw = $wrap.find('.ogm-eqa-badge-wrap').first();
    if($bw.length){ $bw.html(html); }
  }

  function eqaApplyResult($btn, $wrap, $status, d){
    var ctx = $btn.attr('data-context') || 'postslist';
    var isPriced = (d.status === 'issues' || d.status === 'clean');
    eqaSetBadge($wrap, eqaBadge(ctx, d));

    // Only show a report/cost for a genuine result — never stale data on error/skip.
    var $rep = $wrap.find('.ogm-eqa-report-wrap').first();
    if($rep.length){ $rep.html(isPriced ? (d.report_html || '') : ''); }

    var $cost = $wrap.find('.ogm-eqa-costline').first();
    if($cost.length){
      if(isPriced && d.cost){ $cost.find('.ogm-eqa-cost-val').text(d.cost); $cost.show(); } else { $cost.hide(); }
    }
    var $ls = $wrap.find('.ogm-eqa-lastscan').first();
    if($ls.length){
      if(d.scanned_local){ $ls.find('.ogm-eqa-lastscan-val').text(d.scanned_local); $ls.show(); } else { $ls.hide(); }
    }
    var $errln = $wrap.find('.ogm-eqa-errline').first();
    if($errln.length){
      if(d.status === 'error' && d.error){ $errln.find('.ogm-eqa-err-val').text(d.error); $errln.show(); } else { $errln.hide(); }
    }

    if(d.status === 'error')        { $status.text(d.error || 'Scan error.'); }
    else if(d.status === 'skipped') { $status.text('Skipped — password-protected posts aren’t scanned.'); }
    else if(!isPriced)              { $status.text('Not scanned.'); }
    else { $status.text(d.issues > 0 ? ('Updated — ' + d.issues + ' issue' + (d.issues === 1 ? '' : 's')) : 'Updated — clean'); }

    $btn.attr('data-scanned', d.scanned_gmt || '');
  }

  function eqaPoll(postId, baseline, $btn, $wrap, $status, tries){
    var again = function(){
      if(tries <= 1){ $status.text('Still working — reload to check.'); $btn.prop('disabled', false); return; }
      setTimeout(function(){ eqaPoll(postId, baseline, $btn, $wrap, $status, tries - 1); }, 2500);
    };
    $.post(OGM_EQA.ajax_url, {
      action: 'ogm_eqa_scan_status',
      nonce: OGM_EQA.status_nonce,
      post_id: postId
    }).done(function(resp){
      var d = (resp && resp.data) ? resp.data : null;
      // Resolve when the scan has produced a new state: a changed timestamp, or a
      // "skipped" (password-protected) result whose meta was cleared to empty.
      if(resp && resp.success && d && (d.status === 'skipped' || (d.scanned_gmt || '') !== baseline)){
        eqaApplyResult($btn, $wrap, $status, d);
        $btn.prop('disabled', false);
      } else {
        again();
      }
    }).fail(again);
  }

  $(document).on('click', '.ogm-eqa-rescan', function(e){
    e.preventDefault();
    var $btn = $(this);
    var postId = $btn.data('post-id');
    if(!postId) return;
    var $wrap = $btn.closest('td, .ogm-eqa-metabox');
    var $status = $wrap.find('.ogm-eqa-rescan-status').first();
    var baseline = ($btn.attr('data-scanned') || '');

    $btn.prop('disabled', true);
    $status.text('Scanning…');
    eqaSetBadge($wrap, '<span class="ogm-eqa-badge-scanning">Scanning…</span>');

    $.post(OGM_EQA.ajax_url, {
      action: 'ogm_eqa_rescan',
      nonce: OGM_EQA.nonce,
      post_id: postId
    }).done(function(resp){
      if(resp && resp.success){
        eqaPoll(postId, baseline, $btn, $wrap, $status, 12);
      } else {
        $status.text((resp && resp.data && resp.data.message) ? resp.data.message : 'Error.');
        $btn.prop('disabled', false);
      }
    }).fail(function(xhr){
      var msg = 'Error.';
      if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){ msg = xhr.responseJSON.data.message; }
      $status.text(msg);
      $btn.prop('disabled', false);
    });
  });

  /* ---- Refresh a provider's model list (Settings page) ---- */
  $(document).on('click', '.ogm-eqa-refresh', function(e){
    e.preventDefault();
    var $btn = $(this);
    var provider = $btn.data('provider');
    var $select = $('#' + $btn.data('target'));
    var $status = $btn.siblings('.ogm-eqa-refresh-status').first();

    $btn.prop('disabled', true);
    $status.removeClass('ogm-eqa-refresh-error').text('Refreshing…');

    $.post(OGM_EQA.ajax_url, {
      action: 'ogm_eqa_refresh_models',
      nonce: OGM_EQA.refresh_nonce,
      provider: provider
    }).done(function(resp){
      if(resp && resp.success && resp.data && resp.data.models){
        var current = $select.val();
        var models = resp.data.models.slice();
        // Keep the currently-saved model selectable even if the refresh
        // didn't return it (mirrors the server-side get_model_choices()).
        if(current && models.indexOf(current) === -1){
          models.unshift(current);
        }
        $select.empty();
        $.each(models, function(i, id){
          $select.append($('<option/>').val(id).text(id));
        });
        if(current){ $select.val(current); }
        $status.text(resp.data.message || 'Refreshed.');
      } else {
        var m = (resp && resp.data && resp.data.message) ? resp.data.message : 'Refresh failed.';
        $status.addClass('ogm-eqa-refresh-error').text(m);
      }
    }).fail(function(xhr){
      var msg = 'Refresh failed.';
      if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
        msg = xhr.responseJSON.data.message;
      }
      $status.addClass('ogm-eqa-refresh-error').text(msg);
    }).always(function(){
      $btn.prop('disabled', false);
    });
  });

  /* ---- Settings: show only the selected provider's key + model rows ---- */
  function eqaSyncProvider(){
    var $sel = $('#ogm-eqa-provider');
    if(!$sel.length) return;
    var val = $sel.val();
    $('.ogm-eqa-provider-row').each(function(){
      $(this).css('display', $(this).attr('data-provider') === val ? '' : 'none');
    });
  }
  $(document).on('change', '#ogm-eqa-provider', eqaSyncProvider);
  eqaSyncProvider();

});
