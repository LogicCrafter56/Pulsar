/* Pulsar — client helpers: background checker tick + dashboard refresh + form logic */

(function () {
  'use strict';

  // While an Pulsar tab is open, tick the checker every 60 s so monitoring
  // works even without a scheduled task. Refresh the dashboard after each tick.
  var isDashboard = document.body.dataset.page === 'dashboard';
  if (window.CRON_KEY) {
    setInterval(function () {
      fetch('cron.php?key=' + encodeURIComponent(window.CRON_KEY) + '&quiet=1')
        .then(function () {
          if (isDashboard && !document.hidden) location.reload();
        })
        .catch(function () {});
    }, 60000);
  }

  // Monitor form: show only the fields relevant to the selected check type.
  var typeSel = document.getElementById('mon-type');
  if (typeSel) {
    var update = function () {
      var t = typeSel.value;
      document.querySelectorAll('[data-show-for]').forEach(function (el) {
        var show = el.dataset.showFor.split(' ').indexOf(t) !== -1;
        el.style.display = show ? '' : 'none';
        el.querySelectorAll('input, select').forEach(function (inp) {
          inp.disabled = !show;
        });
      });
      var urlLabel = document.getElementById('url-label');
      var urlInput = document.getElementById('mon-url');
      if (urlLabel && urlInput) {
        if (t === 'ping' || t === 'port') {
          urlLabel.textContent = 'Host or IP';
          urlInput.placeholder = 'example.com or 203.0.113.10';
        } else {
          urlLabel.textContent = 'URL';
          urlInput.placeholder = 'https://example.com';
        }
      }
    };
    typeSel.addEventListener('change', update);
    update();
  }

  // Confirm destructive actions.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
  });
})();
