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

    // 定型文ボタン: data-fill-text="<textareaのname>" のボタンで本文をセットする。
    document.querySelectorAll('[data-fill-text]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var name = btn.getAttribute('data-fill-text');
        var ta = document.querySelector('textarea[name="' + name + '"]');
        if (ta) { ta.value = btn.getAttribute('data-text') || ''; ta.focus(); }
      });
    });

    // 宛先クイック選択（全員/会員のみ/未入会/解除）＋選択件数の表示。
    (function () {
      var boxes = document.querySelectorAll('input.js-recipient');
      if (!boxes.length) { return; }
      var countEl = document.querySelector('[data-recipient-count]');
      function refresh() {
        if (!countEl) { return; }
        var n = 0;
        boxes.forEach(function (cb) { if (cb.checked) { n++; } });
        countEl.textContent = n;
      }
      document.querySelectorAll('[data-recipient-select]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var mode = btn.getAttribute('data-recipient-select');
          boxes.forEach(function (cb) {
            var m = cb.getAttribute('data-member') === '1';
            cb.checked = mode === 'all' ? true : mode === 'none' ? false
              : mode === 'member' ? m : mode === 'nonmember' ? !m : cb.checked;
          });
          refresh();
        });
      });
      boxes.forEach(function (cb) { cb.addEventListener('change', refresh); });
      refresh();
    })();

    // クリックで入力値を全選択（コピー用テキスト欄）
    document.querySelectorAll('.js-select').forEach(function (el) {
      el.addEventListener('click', function () { el.select(); });
    });

    // クリップボードにコピー: data-copy-target="<textarea/inputのid>" のボタンで内容をコピー。
    document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var el = document.getElementById(btn.getAttribute('data-copy-target'));
        if (!el) { return; }
        var text = ('value' in el) ? el.value : el.textContent;
        var done = function () {
          var label = btn.getAttribute('data-copied-label') || 'コピーしました';
          var orig = btn.textContent;
          btn.textContent = label;
          btn.classList.add('is-copied');
          setTimeout(function () { btn.textContent = orig; btn.classList.remove('is-copied'); }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(el, done); });
        } else {
          fallbackCopy(el, done);
        }
      });
    });
    function fallbackCopy(el, done) {
      try {
        if (el.select) { el.focus(); el.select(); }
        else {
          var r = document.createRange(); r.selectNodeContents(el);
          var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
        }
        document.execCommand('copy');
        done();
      } catch (e) { /* noop */ }
    }
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
    // data-confirm を持つ「ボタン」も個別に確認（同一フォーム内で送信先を分けたい場合）
    document.querySelectorAll('button[data-confirm]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        if (!window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); }
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

    // ピッカー行（tapple風）：モーダル内チェックの選択状況を行サマリに反映。
    function pickerLabel(input) {
      var l = input.closest('label');
      var s = l && l.querySelector('span');
      return s ? s.textContent.trim() : input.value;
    }
    function refreshPicker(group) {
      var boxes = document.querySelectorAll('input[data-group="' + group + '"]');
      var chosen = [];
      boxes.forEach(function (b) { if (b.checked) { chosen.push(pickerLabel(b)); } });
      document.querySelectorAll('[data-summary="' + group + '"]').forEach(function (el) {
        el.textContent = chosen.length ? chosen.join('、') : '未選択';
        el.classList.toggle('is-empty', chosen.length === 0);
      });
    }
    var pickerGroups = {};
    document.querySelectorAll('[data-summary]').forEach(function (el) { pickerGroups[el.getAttribute('data-summary')] = 1; });
    document.querySelectorAll('input[data-group]').forEach(function (b) {
      b.addEventListener('change', function () { refreshPicker(b.getAttribute('data-group')); });
    });
    Object.keys(pickerGroups).forEach(refreshPicker);

    // テキスト/日付フィールドの行サマリ（data-field → data-fieldval）を更新。
    function ageFromDate(s) {
      var m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s);
      if (!m) { return null; }
      var t = new Date();
      var y = t.getFullYear() - (+m[1]);
      if ((t.getMonth() + 1) < (+m[2]) || ((t.getMonth() + 1) === (+m[2]) && t.getDate() < (+m[3]))) { y--; }
      return y < 0 ? null : y;
    }
    function refreshField(key) {
      var input = document.querySelector('[data-field="' + key + '"]');
      if (!input) { return; }
      var val = (input.value || '').trim();
      var text, empty = (val === '');
      if (input.hasAttribute('data-field-age')) {
        var a = val ? ageFromDate(val) : null;
        text = (a !== null) ? (a + '歳') : '未設定';
        empty = (a === null);
      } else {
        text = empty ? '未設定' : val.replace(/\s+/g, ' ');
        if (text.length > 24) { text = text.slice(0, 24) + '…'; }
      }
      document.querySelectorAll('[data-fieldval="' + key + '"]').forEach(function (el) {
        el.textContent = text;
        el.classList.toggle('is-empty', empty);
      });
    }
    document.querySelectorAll('[data-field]').forEach(function (inp) {
      var ev = (inp.tagName === 'SELECT' || inp.type === 'date') ? 'change' : 'input';
      inp.addEventListener(ev, function () { refreshField(inp.getAttribute('data-field')); });
      refreshField(inp.getAttribute('data-field'));
    });

    // 行の複製（例：リンクを追加）。data-clone="<templateのid>" data-clone-into="<格納先のid>"
    document.querySelectorAll('[data-clone]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tpl = document.getElementById(btn.getAttribute('data-clone'));
        var into = document.getElementById(btn.getAttribute('data-clone-into'));
        if (tpl && into && tpl.content) {
          into.appendChild(tpl.content.cloneNode(true));
          var last = into.querySelector('.tp-linkrow-edit:last-child input[type="url"]');
          if (last) { last.focus(); }
        }
      });
    });

    // 画像選択の即時プレビュー（カバー/顔写真/名刺）。選んだ瞬間に見た目で分かるようにする。
    document.querySelectorAll('.tp-cov input[type="file"], .tp-avedit input[type="file"], .tp-cardedit input[type="file"]').forEach(function (inp) {
      inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        if (!f) { return; }
        var label = inp.closest('label') || inp.parentElement;
        var url = URL.createObjectURL(f);
        var img = label.querySelector('img');
        if (!img) { img = document.createElement('img'); label.appendChild(img); }
        img.src = url;
        var ph = label.querySelector('.tp-cardedit__ph, .tp-avedit__ph');
        if (ph) { ph.style.display = 'none'; }
        // 保存前だと分かるように枠線を付ける。丸い顔写真はバッジが見切れるため枠線のみ。
        label.classList.add('is-unsaved');
        var isAvatar = label.classList.contains('tp-avedit');
        if (!isAvatar && !label.querySelector('.tp-unsaved')) {
          var badge = document.createElement('span');
          badge.className = 'tp-unsaved';
          badge.textContent = '未保存';
          label.appendChild(badge);
        }
        // ページ下部の注意書きを表示（保存前だと明確に伝える）。
        var notice = document.getElementById('unsavedNotice');
        if (notice) { notice.hidden = false; }
        // 名刺は行サマリに「選択中（保存で確定）」を出して、アップロード予定が分かるようにする。
        if (inp.name === 'card') {
          var row = document.querySelector('[data-modal-open="m-card"] .tp-field__v');
          if (row) { row.textContent = '選択中（未保存）'; row.classList.remove('is-empty'); }
        }
      });
    });

    // お知らせカルーセルの自動送り。data-autoplay="ミリ秒" を持つ横スクロール領域を
    // 一定間隔で次のスライドへ送り、末尾まで来たら先頭へ戻る。
    // 指で操作している間・ホバー中・タブが裏にある間は止める。
    // 「視差効果を減らす」設定の端末では自動送りしない（酔い・目の負担への配慮）。
    document.querySelectorAll('[data-autoplay]').forEach(function (rail) {
      var slides = rail.children;
      if (slides.length < 2) { return; }
      var dots = document.querySelector('[data-dots-for="' + rail.className.split(' ')[0] + '"]');
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var wait = parseInt(rail.getAttribute('data-autoplay'), 10) || 6000;
      var timer = null, paused = false;

      function current() {
        // 表示位置から今のスライド番号を求める（指でスワイプされた場合にも追随する）。
        var best = 0, min = Infinity;
        for (var i = 0; i < slides.length; i++) {
          var d = Math.abs(slides[i].offsetLeft - rail.scrollLeft);
          if (d < min) { min = d; best = i; }
        }
        return best;
      }
      function paint() {
        if (!dots) { return; }
        var n = current();
        for (var i = 0; i < dots.children.length; i++) {
          dots.children[i].classList.toggle('on', i === n);
        }
      }
      function go(i) {
        rail.scrollTo({ left: slides[i].offsetLeft, behavior: reduce ? 'auto' : 'smooth' });
      }
      function tick() {
        if (paused || document.hidden) { return; }
        go((current() + 1) % slides.length);
      }
      function start() { if (timer === null && !reduce) { timer = setInterval(tick, wait); } }
      function stop() { if (timer !== null) { clearInterval(timer); timer = null; } }

      rail.addEventListener('scroll', paint, { passive: true });
      ['pointerenter', 'pointerdown', 'focusin'].forEach(function (ev) {
        rail.addEventListener(ev, function () { paused = true; });
      });
      ['pointerleave', 'pointerup', 'focusout'].forEach(function (ev) {
        rail.addEventListener(ev, function () { paused = false; });
      });
      document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });
      // 点をタップしたらそのスライドへ。
      if (dots) {
        Array.prototype.forEach.call(dots.children, function (dot, i) {
          dot.addEventListener('click', function () { paused = true; go(i); setTimeout(function () { paused = false; }, wait); });
        });
      }
      paint();
      start();
    });

    // リンク件数サマリ（#linkRows の入力に応じて #linkSummary を更新）。追加行にも委譲で対応。
    var linkRows = document.getElementById('linkRows');
    var linkSummary = document.getElementById('linkSummary');
    if (linkRows && linkSummary) {
      var updLinks = function () {
        var n = 0;
        linkRows.querySelectorAll('input[type="url"]').forEach(function (u) {
          if ((u.value || '').trim() !== '') { n++; }
        });
        linkSummary.textContent = n > 0 ? (n + '件') : '未設定';
        linkSummary.classList.toggle('is-empty', n === 0);
      };
      linkRows.addEventListener('input', updLinks);
      updLinks();
    }
  });
})();
