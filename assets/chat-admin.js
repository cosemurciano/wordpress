jQuery(document).ready(function($){
  var frame;
  $(document).on('click', '.alma-chat-avatar-upload', function(e){
    e.preventDefault();
    if(frame){
      frame.open();
      return;
    }
    frame = wp.media({
      title: 'Seleziona o carica immagine',
      button: { text: 'Usa questa immagine' },
      multiple: false
    });
    frame.on('select', function(){
      var attachment = frame.state().get('selection').first().toJSON();
      $('#alma_chat_avatar').val(attachment.url);
    });
    frame.open();
  });
});
