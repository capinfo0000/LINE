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

    // 一括チェック: data-check-all="<対象チェックボックスのname>" を持つチェックボックスで全選択/解除。
    document.querySelectorAll('[data-check-all]').forEach(function (master) {
      var name = master.getAttribute('data-check-all');
      var targets = document.querySelectorAll('input[type="checkbox"][name="' + name + '"]');
      master.addEventListener('change', function () {
        targets.forEach(function (cb) { cb.checked = master.checked; });
      });
    });

    // クリックで入力値を全選択（コピー用テキスト欄）
    document.querySelectorAll('.js-select').forEach(function (el) {
      el.addEventListener('click', function () { el.select(); });
    });
    // 変更で所属フォームを送信（イベント切替の select 等）
    document.querySelectorAll('.js-autosubmit').forEach(function (el) {
      el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
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
