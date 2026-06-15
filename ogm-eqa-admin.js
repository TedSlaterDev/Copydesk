jQuery(function($){
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
});