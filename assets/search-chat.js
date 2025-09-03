jQuery(document).ready(function($){
  $('.alma-search-chat').each(function(){
    var container = $(this);
    var messages = container.find('.alma-chat-messages');
    function addMessage(text, cls){
      var div = $('<div>').addClass('alma-msg '+cls).html(text);
      messages.append(div);
      messages.scrollTop(messages.prop('scrollHeight'));
    }
    function send(){
      var input = container.find('.alma-chat-input');
      var text = input.val();
      if(!text){return;}
      addMessage($('<div>').text(text).html(),'user');
      input.val('');
      $.post(almaChat.ajax_url,{action:'alma_nl_search',nonce:almaChat.nonce,query:text},function(resp){
        if(resp.success){
          var grouped = {};
          resp.data.forEach(function(item){
            var type = item.types.length ? item.types[0] : 'Altro';
            if(!grouped[type]) grouped[type]=[];
            grouped[type].push(item);
          });
          $.each(grouped,function(type,items){
            addMessage('<strong>'+type+'</strong>','bot');
            items.forEach(function(it){
              var link = '<a href="'+it.url+'" target="_blank">'+almaChat.strings.visit+'</a>';
              addMessage('<strong>'+it.title+'</strong>: '+it.description+' '+link,'bot');
            });
          });
        } else {
          addMessage(resp.data || 'Error','bot');
        }
      });
    }
    container.on('click','.alma-chat-send',send);
    container.on('keypress','.alma-chat-input',function(e){ if(e.which===13){send(); return false;} });
  });
});
