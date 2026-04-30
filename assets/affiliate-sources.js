jQuery(function($){
  const $wrap = $('#alma-source-form-wrap');
  $('.alma-toggle-source-form').on('click', function(){ $wrap.toggle(); });
  function parseHidden(id){ const raw=$(id).val(); if(!raw) return {}; try{return JSON.parse(raw);}catch(e){return{};} }
  function renderGuided(){
    const presets=(window.almaSourcePresets&&almaSourcePresets.presets)||{}; const key=$('#provider_preset').val(); const preset=presets[key]||null;
    const existingSettings=parseHidden('#alma-existing-settings'); const credentialFlags=parseHidden('#alma-existing-credentials-flags');
    const $s=$('#alma-guided-settings').empty(); const $c=$('#alma-guided-credentials').empty(); if(!preset) return;
    (preset.settings_fields||[]).forEach(f=>{ const value=typeof existingSettings[f]==='string'?existingSettings[f]:''; $s.append('<p><label><strong>'+f+'</strong><br/><input class="regular-text" type="text" name="settings_fields['+f+']" value="'+$('<div/>').text(value).html()+'"></label></p>'); });
    (preset.credentials_fields||[]).forEach(f=>{ const placeholder=credentialFlags[f]?'già salvato':''; $c.append('<p><label><strong>'+f+'</strong><br/><input class="regular-text" type="password" name="credentials_fields['+f+']" value="" placeholder="'+placeholder+'" autocomplete="off"></label></p>'); });
    if(preset.help_text){ $s.append('<p class="description">'+preset.help_text+'</p>'); }
  }
  $('#provider_preset').on('change', renderGuided); renderGuided();
  $('#alma-source-form').on('submit', function(e){ const name=$('#name').val().trim(); const provider=$('#provider_label').val().trim(); if(!name||!provider){ e.preventDefault(); alert('Name e provider sono obbligatori.'); }});
  function getInlineResultTarget($btn){
    const $context=$btn.closest('tr, .alma-source-actions, .alma-test-connection-wrap');
    if($context.length){
      const $contextResult=$context.find('.alma-inline-result').first();
      if($contextResult.length){ return $contextResult; }
    }
    const $siblings=$btn.siblings('.alma-inline-result').first();
    if($siblings.length){ return $siblings; }
    const $nearest=$btn.parent().find('.alma-inline-result').first();
    if($nearest.length){ return $nearest; }
    return $('<span class=\"alma-inline-result\" aria-live=\"polite\"></span>').insertAfter($btn);
  }
  $(document).on('click','.alma-test-connection', function(){
    const $btn=$(this); const sourceId=$btn.data('source-id'); const $res=getInlineResultTarget($btn);
    $btn.prop('disabled',true).addClass('updating-message'); $res.removeClass('error success').text('Test in corso...');
    $.post(almaSourcePresets.ajax_url,{action:'alma_test_source_connection',nonce:almaSourcePresets.nonce,source_id:sourceId})
      .done(function(resp){ if(resp&&resp.success){$res.addClass('success').text(resp.data.message||'Connessione riuscita');} else {$res.addClass('error').text(resp?.data?.message||'Errore interno');} })
      .fail(function(){ $res.addClass('error').text('Errore interno'); })
      .always(function(){ $btn.prop('disabled',false).removeClass('updating-message'); });
  });
});
