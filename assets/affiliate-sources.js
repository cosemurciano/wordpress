jQuery(function($){
  const $wrap = $('#alma-source-form-wrap');
  $('.alma-toggle-source-form').on('click', function(){ $wrap.toggle(); if($wrap.is(':visible')){ $('#name').trigger('focus'); } });
  function parseHidden(id){ const raw=$(id).val(); if(!raw) return {}; try{return JSON.parse(raw);}catch(e){return{};} }
  function normalizeField(field){ return typeof field === 'string' ? {key:field,label:field,type:'text'} : (field||{}); }
  function esc(v){ return $('<div/>').text(v||'').html(); }
  function renderSelect(name, meta, value){
    const options = Array.isArray(meta.options) ? meta.options : [];
    let html = '<select class="regular-text" name="'+name+'">';
    options.forEach(opt => { const val = (opt&&opt.value)||''; const label=(opt&&opt.label)||val; html += '<option value="'+esc(val)+'"'+(String(value)===String(val)?' selected':'')+'>'+esc(label)+'</option>'; });
    html += '</select>';
    return html;
  }
  function renderGuided(){
    const presets=(window.almaSourcePresets&&almaSourcePresets.presets)||{}; const key=$('#provider_preset').val(); const preset=presets[key]||null;
    const existingSettings=parseHidden('#alma-existing-settings'); const credentialFlags=parseHidden('#alma-existing-credentials-flags');
    const $s=$('#alma-guided-settings').empty(); const $c=$('#alma-guided-credentials').empty(); if(!preset) return;
    const isViator = key==='viator';
    $('#alma-advanced-credentials').toggle(!isViator);
    $('#alma-viator-credentials-note').toggle(isViator);
    (preset.settings_fields||[]).map(normalizeField).forEach(meta=>{
      const k=meta.key||''; if(!k) return;
      const value=typeof existingSettings[k]==='string'?existingSettings[k]:(meta.default||'');
      const req = meta.required ? ' required' : '';
      const type = meta.type==='number'?'number':'text';
      let input = '<input class="regular-text" type="'+type+'" name="settings_fields['+k+']" value="'+esc(value)+'" placeholder="'+esc(meta.placeholder||'')+'"'+req+'>';
      if(meta.type==='select'){ input = renderSelect('settings_fields['+k+']', meta, value); }
      $s.append('<p><label><strong>'+esc(meta.label||k)+'</strong><br/>'+input+'</label>'+(meta.help?'<br/><span class="description">'+esc(meta.help)+'</span>':'')+(meta.note?'<br/><span class="description">'+esc(meta.note)+'</span>':'')+'</p>');
    });
    (preset.credentials_fields||[]).map(normalizeField).forEach(meta=>{ const k=meta.key||''; if(!k) return; const placeholder=credentialFlags[k]?'già salvato':(meta.placeholder||''); const hasSavedSecret = !!credentialFlags[k]; const req = (meta.required && !hasSavedSecret) ? ' required' : ''; $c.append('<p><label><strong>'+esc(meta.label||k)+'</strong><br/><input class="regular-text" type="password" name="credentials_fields['+k+']" value="" placeholder="'+esc(placeholder)+'" autocomplete="off"'+req+'></label>'+(meta.help?'<br/><span class="description">'+esc(meta.help)+'</span>':'')+'</p>'); });
    if(preset.help_text){ $s.append('<p class="description">'+esc(preset.help_text)+'</p>'); }
    if(isViator){ $c.append('<p class="description">Viator richiede una sola API key. Non servono access token, client ID, client secret, username o password.</p>'); }
  }
  $('#provider_preset').on('change', renderGuided); renderGuided();
  $('#alma-source-form').on('submit', function(e){ const name=$('#name').val().trim(); const provider=$('#provider_label').val().trim(); if(!name||!provider){ e.preventDefault(); alert('Name e provider sono obbligatori.'); }});
  function getInlineResultTarget($btn){ const $context=$btn.closest('tr, .alma-source-actions, .alma-test-connection-wrap'); if($context.length){ const $contextResult=$context.find('.alma-inline-result').first(); if($contextResult.length){ return $contextResult; } } const $siblings=$btn.siblings('.alma-inline-result').first(); if($siblings.length){ return $siblings; } const $nearest=$btn.parent().find('.alma-inline-result').first(); if($nearest.length){ return $nearest; } return $('<span class="alma-inline-result" aria-live="polite"></span>').insertAfter($btn); }
  $(document).on('click','.alma-test-connection', function(){ const $btn=$(this); const sourceId=$btn.data('source-id'); const $res=getInlineResultTarget($btn); $btn.prop('disabled',true).addClass('updating-message'); $res.removeClass('error success').text('Test in corso...'); $.post(almaSourcePresets.ajax_url,{action:'alma_test_source_connection',nonce:almaSourcePresets.nonce,source_id:sourceId}).done(function(resp){ if(resp&&resp.success){$res.addClass('success').text(resp.data.message||'Connessione riuscita');} else {$res.addClass('error').text(resp?.data?.message||'Errore interno');} }).fail(function(){ $res.addClass('error').text('Errore interno'); }).always(function(){ $btn.prop('disabled',false).removeClass('updating-message'); }); });
  function refreshSelCount(){ const n=$('.alma-select-item:checked').length; $('.alma-selected-counter').text(n+' selezionati'); }
  $(document).on('change','.alma-select-item',refreshSelCount);
  $(document).on('click','.alma-select-all',function(){ $('.alma-select-item').prop('checked',true); refreshSelCount(); });
  $(document).on('click','.alma-deselect-all',function(){ $('.alma-select-item').prop('checked',false); refreshSelCount(); });
  refreshSelCount();
});
