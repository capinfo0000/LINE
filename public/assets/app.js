// 共通のフロント挙動。CSP厳格化（script-src から 'unsafe-inline' を除去）に伴い、
// インライン属性(onclick/onchange/onsubmit)の代わりにここでイベントを束ねる。
(function () {
  'use strict';
  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }
  ready(function () {
    // モバイル時のヘッダーナビ（横スクロール）は、ページ遷移のたびに scrollLeft が 0 に戻り
    // 選択中の項目が画面外へ隠れてしまう。読み込み時にアクティブ項目が見える位置へ横スクロールを
    // 復元する（縦スクロールには一切触れない）。デスクトップの縦サイドバーでは横スクロールが無く無害。
    (function keepActiveNavVisible() {
      var nav = document.querySelector('.nav');
      var active = nav && nav.querySelector('a.active');
      if (!nav || !active) { return; }
      if (nav.scrollWidth <= nav.clientWidth) { return; } // 横スクロール不要（デスクトップ等）
      var target = active.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2;
      nav.scrollLeft = Math.max(0, target); // 中央寄せ（縦位置は不変）
    })();

    // クリックで入力値を全選択（コピー用テキスト欄）
    document.querySelectorAll('.js-select').forEach(function (el) {
      el.addEventListener('click', function () { el.select(); });
    });
    // 変更で所属フォームを送信（イベント切替の select 等）
    document.querySelectorAll('.js-autosubmit').forEach(function (el) {
      el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
    });
    // 画像アップロードは送信前にブラウザで縮小する（元の形式のまま・長辺を data-max-dim に収める）。
    // これで大きなスマホ写真も PHP の upload_max_filesize を超えずに送れる。縮小後、サーバ側で
    // 正方形クロップ＋WebP化される。DataTransfer/canvas 非対応の古い環境では元ファイルのまま送る。
    function shrinkImageFile(file, maxDim, quality) {
      return new Promise(function (resolve) {
        if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type) ||
            typeof document.createElement('canvas').toBlob !== 'function' ||
            typeof window.DataTransfer === 'undefined') {
          resolve(file); return;
        }
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
          var w = img.naturalWidth, h = img.naturalHeight;
          var scale = Math.min(1, maxDim / Math.max(w, h));
          if (scale >= 1) { URL.revokeObjectURL(url); resolve(file); return; } // 既に十分小さい
          var canvas = document.createElement('canvas');
          canvas.width = Math.round(w * scale);
          canvas.height = Math.round(h * scale);
          canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
          URL.revokeObjectURL(url);
          canvas.toBlob(function (blob) {
            if (!blob || blob.size >= file.size) { resolve(file); return; } // 小さくならなければ元のまま
            try {
              resolve(new File([blob], file.name, { type: file.type, lastModified: file.lastModified }));
            } catch (e) { resolve(file); }
          }, file.type, quality);
        };
        img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
      });
    }
    document.querySelectorAll('form').forEach(function (form) {
      var inputs = form.querySelectorAll('input[type="file"][data-max-dim]');
      if (!inputs.length) { return; }
      var shrunk = false;
      form.addEventListener('submit', function (e) {
        if (shrunk) { return; } // 2回目（縮小済み）はそのまま送信
        var jobs = [];
        inputs.forEach(function (inp) {
          if (!inp.files || !inp.files[0]) { return; }
          var maxDim = parseInt(inp.getAttribute('data-max-dim'), 10) || 1024;
          jobs.push(shrinkImageFile(inp.files[0], maxDim, 0.82).then(function (f) {
            if (f && f !== inp.files[0]) {
              var dt = new DataTransfer();
              dt.items.add(f);
              inp.files = dt.files;
            }
          }));
        });
        if (!jobs.length) { return; } // 送信対象の画像なし
        e.preventDefault();
        Promise.all(jobs).then(function () { shrunk = true; form.submit(); });
      });
    });

    // data-confirm を持つフォームは送信前に確認ダイアログ
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
      });
    });

    // モーダル（ポップアップ）: data-modal-open="ID" で開く
    function closeModal(m) { if (m) { m.classList.remove('is-open'); } }
    document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var m = document.getElementById(btn.getAttribute('data-modal-open'));
        if (m) { m.classList.add('is-open'); }
      });
    });
    // 背景クリック / data-modal-close で閉じる
    document.querySelectorAll('.modal').forEach(function (m) {
      m.addEventListener('click', function (e) {
        if (e.target === m || (e.target.closest && e.target.closest('[data-modal-close]'))) {
          closeModal(m);
        }
      });
    });
    // ESC キーで閉じる
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.modal.is-open').forEach(closeModal);
      }
    });
    // data-auto-open のモーダルは表示時に自動で開く
    document.querySelectorAll('.modal[data-auto-open]').forEach(function (m) {
      m.classList.add('is-open');
    });
  });
})();
