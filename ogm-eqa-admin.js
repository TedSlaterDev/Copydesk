jQuery(function($){

  /* ---- Rescan a single post (meta box, Posts column, Reports table) ---- */
  function rescan(postId, $statusEl){
    $statusEl.text('Queuing…');
    $.post(OGM_EQA.ajax_url, {
      action: 'ogm_eqa_rescan',
      nonce: OGM_EQA.nonce,
      post_id: postId
    }).done(function(resp){
      if(resp && resp.success){
        $statusEl.text(resp.data.message || 'Queued.');
      } else {
        $statusEl.text((resp && resp.data && resp.data.message) ? resp.data.message : 'Error.');
      }
    }).fail(function(xhr){
      var msg = 'Error.';
      if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
        msg = xhr.responseJSON.data.message;
      }
      $statusEl.text(msg);
    });
  }

  $(document).on('click', '.ogm-eqa-rescan', function(e){
    e.preventDefault();
    var postId = $(this).data('post-id');
    var $wrap = $(this).closest('td, .ogm-eqa-metabox');
    var $statusEl = $wrap.find('.ogm-eqa-rescan-status').first();
    if(!postId) return;
    rescan(postId, $statusEl);
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

});
