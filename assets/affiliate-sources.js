jQuery(function($){
  function updateSelected(){ $('.alma-selected-counter').text($('.alma-select-item:checked').length+' selezionati'); }
  $(document).on('click','.alma-select-all',function(){ $('.alma-select-item').prop('checked',true); updateSelected();});
  $(document).on('click','.alma-deselect-all',function(){ $('.alma-select-item').prop('checked',false); updateSelected();});
  $(document).on('change','.alma-select-item',updateSelected);
  $(document).on('change','#import_search_model',function(){
    var m=$(this).val();
    $('input[name="import_search_term"]').closest('p').toggle(m==='freetext_search');
  }).trigger('change');
  updateSelected();
});
