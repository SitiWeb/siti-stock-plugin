(function ($) {
  function toggleCronInfo() {
    var info = $('.siti-stock-cron-info');
    var checkbox = $('[data-siti-stock-toggle="cron"]');

    if (!info.length || !checkbox.length) {
      return;
    }

    info.toggleClass('is-visible', checkbox.is(':checked'));
  }

  $(document).on('change', '[data-siti-stock-toggle="cron"]', toggleCronInfo);
  $(toggleCronInfo);
})(jQuery);
