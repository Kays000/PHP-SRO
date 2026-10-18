// Таймер и подтверждение завершения. Источник истины по времени — сервер:
// по истечении времени страница перезагружается, и PHP завершает попытку.
(function () {
    'use strict';

    var timer = document.getElementById('timer');
    if (timer) {
        var left = parseInt(timer.getAttribute('data-left') || '0', 10);
        var out = document.getElementById('timer-value');
        var tick = function () {
            if (left <= 0) {
                window.location.reload();
                return;
            }
            left -= 1;
            var m = String(Math.floor(left / 60)).padStart(2, '0');
            var s = String(left % 60).padStart(2, '0');
            if (out) { out.textContent = m + ':' + s; }
            if (left <= 60) { timer.classList.add('timer-warn'); }
        };
        window.setInterval(tick, 1000);
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.getAttribute && target.getAttribute('data-confirm')) {
            if (!window.confirm(target.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        }
    });
})();
