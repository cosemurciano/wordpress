jQuery(function($){
  function parseJson(id){ try { return JSON.parse($(id).val() || '{}'); } catch(e){ return {}; } }
  function updateSelected(){ $('.alma-selected-counter').text($('.alma-select-item:checked').length+' selezionati'); }
  function toggleSearchHints(){
    var m = $('#import_search_model').val();
    $('.alma-search-term-wrap').toggle(m === 'freetext_search');
    $('.alma-destination-wrap').toggle(m !== 'freetext_search');
  }
  function syncResultFilters(){
    var hideExisting = $('input[name="hide_existing"]').is(':checked');
    var showExisting = $('input[name="show_existing"]').is(':checked');
    $('.alma-row-existing').toggle(!hideExisting || showExisting);
  }
  function renderProviderFields(){
    var existingSettings = parseJson('#alma-existing-settings');
    var existingCredFlags = parseJson('#alma-existing-credentials-flags');
    var preset = $('#provider_preset').val();
    var $settings = $('#alma-guided-settings');
    var $creds = $('#alma-guided-credentials');
    var isViator = preset === 'viator';
    var isGetYourGuide = preset === 'getyourguide';
    $('#alma-advanced-credentials').toggle(!isViator && !isGetYourGuide);
    $('#alma-viator-credentials-note').toggle(isViator);
    $settings.html(''); $creds.html('');
    if(isViator){
      $settings.append('<p><label>Modalità <select name="settings_fields[mode]"><option value="create_update">create_update</option><option value="create_only">create_only</option></select></label></p>');
      $creds.append('<p><label><strong>Viator API key *</strong><br/><input type="password" name="credentials_fields[api_key]" class="regular-text" '+(existingCredFlags.api_key ? '' : 'required')+' placeholder="'+(existingCredFlags.api_key ? 'già salvata' : '')+'" autocomplete="off"></label></p>');
    }
    if(isGetYourGuide){
      $settings.append('<p class="description">Richiede accesso GetYourGuide Partner API. Il livello API disponibile può influire sui campi restituiti.</p>');
      $settings.append('<p><label><strong>Lingua contenuti</strong><br/><input type="text" name="settings_fields[cnt_language]" value="it" class="regular-text"></label></p>');
      $settings.append('<p><label><strong>Valuta</strong><br/><input type="text" name="settings_fields[currency]" value="EUR" class="regular-text"></label></p>');
      $settings.append('<p><label><strong>Query predefinita</strong><br/><input type="text" name="settings_fields[default_query]" class="regular-text" placeholder="es. Lecce"></label></p>');
      $settings.append('<p><label><strong>Limite risultati</strong><br/><input type="number" name="settings_fields[limit]" value="20" min="1" max="100"></label></p>');
      $settings.append('<p><label>Sort field <input type="text" name="settings_fields[sortfield]" class="regular-text"></label></p>');
      $settings.append('<p><label>Sort direction <input type="text" name="settings_fields[sortdirection]" class="regular-text"></label></p>');
      $creds.append('<p><label><strong>Access token GetYourGuide *</strong><br/><input type="password" name="credentials_fields[access_token]" class="regular-text" '+(existingCredFlags.access_token ? '' : 'required')+' placeholder="'+(existingCredFlags.access_token ? 'già salvato' : '')+'" autocomplete="off"></label></p><p class="description">Token configurato/non configurato: il valore salvato non viene mostrato in chiaro.</p>');
    }
    if(existingSettings.mode){ $settings.find('select[name="settings_fields[mode]"]').val(existingSettings.mode); }
    ['cnt_language','currency','default_query','limit','sortfield','sortdirection'].forEach(function(k){ if(existingSettings[k] !== undefined){ $settings.find('[name="settings_fields['+k+']"]').val(existingSettings[k]); } });
  }

  $(document).on('click','.alma-toggle-source-form',function(){
    $('#alma-source-form-wrap').slideToggle(150, function(){ if($(this).is(':visible')) $('#name').trigger('focus'); });
  });
  $(document).on('change','#provider_preset', renderProviderFields);
  $(document).on('submit','#alma-source-form',function(){
    if(!$('#name').val().trim() || !$('#provider_label').val().trim()){ alert('Name e Provider sono obbligatori.'); return false; }
  });
  $(document).on('click','.alma-test-connection',function(e){
    e.preventDefault();
    var $btn=$(this), $row=$btn.closest('td, .alma-test-connection-wrap'), $res=$row.find('.alma-inline-result').first();
    $res.removeClass('ok err').text('Test in corso...');
    $.post(ajaxurl,{action:'alma_test_source_connection', nonce:(window.almaSourcePresets&&almaSourcePresets.nonce)||(window.almaSources&&almaSources.testNonce)||'', source_id:$btn.data('source-id')})
      .done(function(r){ $res.addClass(r&&r.success?'ok':'err').text((r&&r.data&&r.data.message)||'Risposta non valida'); })
      .fail(function(){ $res.addClass('err').text('Errore di rete'); });
  });

  $(document).on('click','.alma-select-all',function(){ $('.alma-select-item:visible').prop('checked',true); updateSelected();});
  $(document).on('click','.alma-deselect-all',function(){ $('.alma-select-item').prop('checked',false); updateSelected();});
  $(document).on('change','.alma-select-item',updateSelected);
  $(document).on('change','#import_search_model',toggleSearchHints);
  $(document).on('change','input[name="import_availability_range"]',function(){ $('.alma-date-custom-wrap').toggle($(this).val()==='custom'); });
  $(document).on('change','input[name="hide_existing"], input[name="show_existing"]',syncResultFilters);
  $(document).on('change','input[name="auto_fill_new_items"]',function(){ $('.alma-auto-fill-note').toggle($(this).is(':checked')); });
  $(document).on('click','.alma-toggle-advanced-filters',function(e){ e.preventDefault(); $('.alma-advanced-filters').toggleClass('is-open'); });

  $(document).on('click','.alma-load-more-results',function(e){
    e.preventDefault();
    var $btn=$(this), url=$btn.data('href');
    if(url){ window.location.href=url; }
  });

  renderProviderFields();
  toggleSearchHints();
  updateSelected();
  syncResultFilters();
});
