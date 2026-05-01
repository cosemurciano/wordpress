jQuery(function($){
  function parseJson(id){ try { return JSON.parse($(id).val() || '{}'); } catch(e){ return {}; } }
  function updateSelected(){ $('.alma-selected-counter').text($('.alma-select-item:checked').length+' selezionati'); }
  function toggleSearchHints(){
    var m = $('#import_search_model').val();
    $('.alma-search-term-wrap').toggle(m === 'freetext_search');
    $('.alma-destination-wrap').toggle(m !== 'freetext_search');
  }
  function renderProviderFields(){
    var existingSettings = parseJson('#alma-existing-settings');
    var existingCredFlags = parseJson('#alma-existing-credentials-flags');
    var preset = $('#provider_preset').val();
    var $settings = $('#alma-guided-settings');
    var $creds = $('#alma-guided-credentials');
    var isViator = preset === 'viator';
    $('#alma-advanced-credentials').toggle(!isViator);
    $('#alma-viator-credentials-note').toggle(isViator);
    $settings.html(''); $creds.html('');
    if(isViator){
      $settings.append('<p><label>Modalità <select name="settings_fields[mode]"><option value="create_update">create_update</option><option value="create_only">create_only</option></select></label></p>');
      $creds.append('<p><label><strong>Viator API key *</strong><br/><input type="password" name="credentials_fields[api_key]" class="regular-text" '+(existingCredFlags.api_key ? '' : 'required')+' placeholder="'+(existingCredFlags.api_key ? 'già salvata' : '')+'" autocomplete="off"></label></p>');
    }
    if(existingSettings.mode){ $settings.find('select[name="settings_fields[mode]"]').val(existingSettings.mode); }
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
    $.post(ajaxurl,{action:'alma_test_source_connection', nonce:(window.almaSources&&almaSources.testNonce)||'', source_id:$btn.data('source-id')})
      .done(function(r){ $res.addClass(r&&r.success?'ok':'err').text((r&&r.data&&r.data.message)||'Risposta non valida'); })
      .fail(function(){ $res.addClass('err').text('Errore di rete'); });
  });

  $(document).on('click','.alma-select-all',function(){ $('.alma-select-item:visible').prop('checked',true); updateSelected();});
  $(document).on('click','.alma-deselect-all',function(){ $('.alma-select-item').prop('checked',false); updateSelected();});
  $(document).on('change','.alma-select-item',updateSelected);
  $(document).on('change','#import_search_model',toggleSearchHints);
  $(document).on('change','input[name="import_availability_range"]',function(){ $('.alma-date-custom-wrap').toggle($(this).val()==='custom'); });
  $(document).on('change','input[name="hide_existing"]',function(){ $('.alma-show-existing-wrap').toggle($(this).is(':checked')); });
  $(document).on('change','input[name="show_existing"]',function(){ $('.alma-row-existing').toggle($(this).is(':checked')); });
  $(document).on('change','input[name="auto_fill_new_items"]',function(){ $('.alma-auto-fill-note').toggle($(this).is(':checked')); });
  $(document).on('click','.alma-toggle-advanced-filters',function(e){ e.preventDefault(); $('.alma-advanced-filters').toggleClass('is-open'); });

  renderProviderFields();
  toggleSearchHints();
  updateSelected();
});
