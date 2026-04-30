jQuery(function($){
  const $wrap = $('#alma-source-form-wrap');
  $('.alma-toggle-source-form').on('click', function(){ $wrap.toggle(); });

  function renderGuided(){
    const presets=(window.almaSourcePresets&&almaSourcePresets.presets)||{};
    const key=$('#provider_preset').val();
    const preset=presets[key]||null;
    const $s=$('#alma-guided-settings').empty();
    const $c=$('#alma-guided-credentials').empty();
    if(!preset) return;
    (preset.settings_fields||[]).forEach(function(f){ $s.append('<p><label><strong>'+f+'</strong><br/><input class="regular-text" type="text" name="settings_fields['+f+']"></label></p>'); });
    (preset.credentials_fields||[]).forEach(function(f){ $c.prepend('<p><label><strong>'+f+'</strong><br/><input class="regular-text" type="password" name="credentials_fields['+f+']" value="" placeholder="già salvato" autocomplete="off"></label></p>'); });
    if(preset.help_text){ $s.append('<p class="description">'+preset.help_text+'</p>'); }
  }
  $('#provider_preset').on('change', renderGuided); renderGuided();

  $('#alma-source-form').on('submit', function(e){
    const name = $('#name').val().trim();
    const provider = $('#provider_label').val().trim();
    if (!name || !provider) { e.preventDefault(); window.alert('Name e provider sono obbligatori.'); return; }
    ['settings_advanced', 'credentials_advanced'].forEach(function(id){
      const raw = $('#' + id).val().trim(); if (!raw) return;
      try { JSON.parse(raw); } catch (err) { e.preventDefault(); window.alert('JSON non valido nel campo: ' + id); }
    });
  });
});
