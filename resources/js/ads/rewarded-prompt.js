// Embedded at build time in the independent GPT and VAST runtimes. No extra request.
function rewardedPromptUi(options) {
    var translations = {
        en: ['Continue your reading', 'Watch an ad, then return to where you left off.', 'Watching is entirely optional. Closing this message will not block the content.', 'Watch ad', 'Continue without an ad', 'Close', 'Optional rewarded ad', 'Watch this video to receive the reward.', 'Available later', 'You can try another rewarded ad later.'],
        ar: ['أكمل قراءتك', 'شاهد إعلانًا، ثم تابع القراءة من حيث توقفت.', 'مشاهدة الإعلان اختيارية تمامًا. إغلاق هذه الرسالة لا يحجب المحتوى.', 'شاهد الإعلان', 'المتابعة بدون إعلان', 'إغلاق', 'إعلان مكافأة اختياري', 'شاهد هذا الفيديو للحصول على المكافأة.', 'متاح لاحقًا', 'يمكنك مشاهدة إعلان مكافأة آخر لاحقًا.'],
        fr: ['Poursuivez votre lecture', 'Regardez une annonce, puis reprenez votre lecture.', 'Le visionnage est entièrement facultatif. Fermer ce message ne bloque pas le contenu.', 'Voir l’annonce', 'Continuer sans annonce', 'Fermer', 'Annonce avec récompense facultative', 'Regardez cette vidéo pour obtenir la récompense.', 'Disponible plus tard', 'Vous pourrez réessayer plus tard.'],
        es: ['Continúa leyendo', 'Mira un anuncio y retoma la lectura donde la dejaste.', 'Ver el anuncio es totalmente opcional. Cerrar este mensaje no bloquea el contenido.', 'Ver anuncio', 'Continuar sin anuncio', 'Cerrar', 'Anuncio con recompensa opcional', 'Mira este vídeo para recibir la recompensa.', 'Disponible más tarde', 'Podrás volver a intentarlo más tarde.'],
        de: ['Weiterlesen', 'Sieh dir eine Anzeige an und lies anschließend weiter.', 'Das Ansehen ist völlig freiwillig. Das Schließen dieser Nachricht sperrt keine Inhalte.', 'Anzeige ansehen', 'Ohne Anzeige fortfahren', 'Schließen', 'Freiwillige Anzeige mit Belohnung', 'Sieh dir dieses Video an, um die Belohnung zu erhalten.', 'Später verfügbar', 'Du kannst es später erneut versuchen.'],
        pt: ['Continue a leitura', 'Veja um anúncio e retome a leitura de onde parou.', 'Assistir é totalmente opcional. Fechar esta mensagem não bloqueia o conteúdo.', 'Ver anúncio', 'Continuar sem anúncio', 'Fechar', 'Anúncio com recompensa opcional', 'Veja este vídeo para receber a recompensa.', 'Disponível mais tarde', 'Você poderá tentar novamente mais tarde.'],
        tr: ['Okumaya devam edin', 'Bir reklam izleyin, ardından kaldığınız yerden okumaya devam edin.', 'Reklam izlemek tamamen isteğe bağlıdır. Bu mesajı kapatmak içeriği engellemez.', 'Reklamı izle', 'Reklamsız devam et', 'Kapat', 'İsteğe bağlı ödüllü reklam', 'Ödülü almak için bu videoyu izleyin.', 'Daha sonra kullanılabilir', 'Daha sonra tekrar deneyebilirsiniz.'],
        id: ['Lanjutkan membaca', 'Tonton iklan, lalu lanjutkan membaca.', 'Menonton sepenuhnya opsional. Menutup pesan ini tidak memblokir konten.', 'Tonton iklan', 'Lanjutkan tanpa iklan', 'Tutup', 'Iklan berhadiah opsional', 'Tonton video ini untuk mendapatkan hadiah.', 'Tersedia nanti', 'Anda dapat mencoba lagi nanti.'],
    };
    var candidates = [];
    try {
        var navigator = window.navigator || {};
        if (Array.isArray(navigator.languages)) candidates = navigator.languages.slice();
        if (navigator.language) candidates.push(navigator.language);
    } catch (error) { /* Fall back to the page language when browser preferences are unavailable. */ }
    candidates.push(document.documentElement.lang || '', 'en');
    var language = 'en';
    for (var index = 0; index < candidates.length; index++) {
        var base = String(candidates[index]).toLowerCase().split(/[-_]/)[0];
        if (Object.prototype.hasOwnProperty.call(translations, base)) { language = base; break; }
    }
    var text = translations[language];
    // Inline, element-scoped priority prevents publisher button styles from hiding
    // or disabling these controls, without styling any other ad or publisher node.
    function style(node, css) {
        node.style.cssText = 'all:initial;' + css;
        if (node.style.setProperty) {
            for (var i = 0; i < node.style.length; i++) {
                var property = node.style[i];
                node.style.setProperty(property, node.style.getPropertyValue(property), 'important');
            }
        }
    }
    var prompt = document.createElement('div');
    var title = document.createElement('strong');
    var copy = document.createElement('p');
    var disclosure = document.createElement('p');
    var watch = document.createElement('button');
    var decline = document.createElement('button');
    var close = document.createElement('button');
    prompt.setAttribute('data-hm-reward-prompt', '1');
    prompt.setAttribute('lang', language);
    prompt.setAttribute('dir', language === 'ar' ? 'rtl' : 'ltr');
    prompt.setAttribute('role', options.modal ? 'dialog' : 'group');
    if (options.modal) prompt.setAttribute('aria-modal', 'true');
    title.textContent = options.title || (options.reading ? text[0] : text[6]);
    copy.textContent = options.capped ? text[9] : (options.copy || (options.reading ? text[1] : text[7]));
    disclosure.textContent = text[2];
    disclosure.setAttribute('data-hm-reward-disclosure', '1');
    prompt.setAttribute('aria-label', title.textContent);
    prompt.setAttribute('aria-description', disclosure.textContent);
    style(prompt, 'position:relative;display:block;box-sizing:border-box;width:100%;max-width:440px;margin:auto;padding:60px 24px 24px;border:1px solid #d1d5db;border-radius:20px;background:#ffffff;color:#111827;color-scheme:light;text-align:center;font:400 16px/1.6 system-ui,sans-serif;box-shadow:0 20px 64px rgba(0,0,0,.2);overflow-wrap:anywhere;direction:' + (language === 'ar' ? 'rtl' : 'ltr') + ';');
    style(title, 'display:block;margin:0;color:#111827;font:700 24px/1.4 system-ui,sans-serif;text-align:center;');
    style(copy, 'display:block;margin:12px 0;color:#374151;font:400 16px/1.6 system-ui,sans-serif;text-align:center;');
    style(disclosure, 'display:block;margin:12px 0 20px;padding:12px;border-radius:10px;background:#f3f4f6;color:#374151;font:400 14px/1.6 system-ui,sans-serif;text-align:center;');
    var buttonCss = 'display:block;box-sizing:border-box;width:100%;min-height:46px;margin:10px 0 0;padding:12px 16px;border-radius:10px;font:600 15px/1.5 system-ui,sans-serif;white-space:normal;text-align:center;cursor:pointer;pointer-events:auto;touch-action:manipulation;';
    watch.type = decline.type = close.type = 'button';
    watch.textContent = options.capped ? text[8] : (options.button || text[3]);
    watch.disabled = !!options.capped;
    style(watch, buttonCss + 'border:1px solid #111827;background:#111827;color:#ffffff;' + (options.capped ? 'opacity:.65;' : ''));
    decline.textContent = text[4];
    decline.setAttribute('data-hm-reward-decline', '1');
    style(decline, buttonCss + 'border:1px solid #9ca3af;background:#ffffff;color:#111827;');
    close.textContent = '×';
    close.setAttribute('aria-label', text[5]);
    close.setAttribute('title', text[5]);
    close.setAttribute('data-hm-reward-prompt-close', '1');
    style(close, 'display:block;box-sizing:border-box;position:absolute;top:10px;inset-inline-end:10px;width:44px;height:44px;min-width:44px;min-height:44px;margin:0;padding:0;border:1px solid #9ca3af;border-radius:10px;background:#ffffff;color:#111827;font:400 30px/40px system-ui,sans-serif;text-align:center;cursor:pointer;pointer-events:auto;touch-action:manipulation;');
    [close, title, copy, disclosure, watch, decline].forEach(function (node) { prompt.appendChild(node); });
    [close, decline].forEach(function (node) {
        node.addEventListener('click', function (event) { event.stopPropagation(); options.dismiss(); });
    });
    [close, watch, decline].forEach(function (node) {
        node.addEventListener('focus', function () { if (node.style.setProperty) node.style.setProperty('outline', '3px solid #2563eb', 'important'); });
        node.addEventListener('blur', function () { if (node.style.removeProperty) node.style.removeProperty('outline'); });
    });
    return {
        prompt: prompt, watch: watch, decline: decline, close: close, language: language,
        cycleFocus: function (event) {
            var buttons = watch.disabled ? [close, decline] : [close, watch, decline];
            var current = buttons.indexOf(document.activeElement);
            var next = (current + (event.shiftKey ? -1 : 1) + buttons.length) % buttons.length;
            event.preventDefault();
            try { if (buttons[next].focus) buttons[next].focus(); } catch (error) {}
        },
    };
}
