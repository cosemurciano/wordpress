(function($){
    $(function(){
        var box = $('#alma-bot-affiliate');
        if(!box.length){
            return;
        }
        var anim = (typeof alma_bot_affiliate !== 'undefined' && alma_bot_affiliate.animation) ? alma_bot_affiliate.animation : 'fade';
        box.show().addClass('alma-animation-' + anim);
        box.on('click', '.alma-bot-affiliate-close', function(){
            box.hide();
        });
    });
})(jQuery);
