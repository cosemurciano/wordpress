(function($){
    $(function(){
        var box = $('#alma-bot-affiliate');
        if(!box.length){
            return;
        }
        var anim = (typeof alma_bot_affiliate !== 'undefined' && alma_bot_affiliate.animation) ? alma_bot_affiliate.animation : 'fade';
        var delay = (typeof alma_bot_affiliate !== 'undefined' && alma_bot_affiliate.delay) ? parseInt(alma_bot_affiliate.delay, 10) : 0;
        setTimeout(function(){
            box.show().addClass('alma-animation-' + anim);
        }, delay * 1000);
        box.on('click', '.alma-bot-affiliate-close', function(){
            box.hide();
        });
    });
})(jQuery);
