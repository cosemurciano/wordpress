jQuery(function($){
  const $wrap = $('#alma-source-form-wrap');
  $('.alma-toggle-source-form').on('click', function(){ $wrap.toggle(); });

  $('#alma-source-form').on('submit', function(e){
    const name = $('#name').val().trim();
    const provider = $('#provider').val();
    if (!name || !provider) {
      e.preventDefault();
      window.alert('Name e provider sono obbligatori.');
      return;
    }

    ['settings', 'credentials'].forEach(function(id){
      const raw = $('#' + id).val().trim();
      if (!raw) return;
      try { JSON.parse(raw); } catch (err) {
        e.preventDefault();
        window.alert('JSON non valido nel campo: ' + id);
      }
    });
  });
});
