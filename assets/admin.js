jQuery(function ($) {
	var t = dpmData.i18n;

	// Selettore categorie con ricerca.
	var $sel = $('#dpm-cat');
	if ($.fn.selectWoo) {
		$sel.selectWoo({ width: '400px', placeholder: t.placeholder, allowClear: true });
	}
	$sel.on('change', function () {
		$('#dpm-code-preview').val($(this).find(':selected').data('code') || '');
	});

	// Copia codice.
	$('.dpm-copy').on('click', function () {
		var $btn = $(this);
		var code = $btn.data('code');
		var done = function () {
			$btn.text(t.copied);
			setTimeout(function () { $btn.text(t.copy); }, 1500);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(code).then(done);
		} else {
			var $tmp = $('<input>').val(code).appendTo('body').select();
			document.execCommand('copy');
			$tmp.remove();
			done();
		}
	});

	// Conferma eliminazione.
	$('.dpm-delete-form').on('submit', function () {
		return window.confirm(t.confirmDel);
	});

	// Sincronizzazione a blocchi.
	function fmt(str, args) {
		var i = 0;
		return str.replace(/%(\d)\$s/g, function (m, n) { return args[parseInt(n, 10) - 1]; });
	}

	$('#dpm-sync-btn').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		var $wrap = $('#dpm-progress').show();
		var $fill = $wrap.find('.dpm-bar-fill').css('width', '0%');
		var $status = $wrap.find('.dpm-status').text(t.searching);

		var fail = function () {
			$status.text(t.error);
			$btn.prop('disabled', false);
		};

		$.post(dpmData.ajaxUrl, { action: 'dpm_sync_start', nonce: dpmData.nonce }).done(function (r) {
			if (!r || !r.success) { return fail(); }
			var total = r.data.total;
			if (!total) {
				$status.text(t.nothing);
				$btn.prop('disabled', false);
				return;
			}
			var changed = 0;

			(function step(offset) {
				$.post(dpmData.ajaxUrl, {
					action: 'dpm_sync_batch',
					nonce: dpmData.nonce,
					offset: offset,
					size: 40
				}).done(function (res) {
					if (!res || !res.success) { return fail(); }
					changed += res.data.changed;
					var processed = res.data.processed;
					$fill.css('width', Math.round((processed / total) * 100) + '%');
					if (res.data.done) {
						$status.text(fmt(t.done, [total, changed]));
						$btn.prop('disabled', false);
					} else {
						$status.text(fmt(t.progress, [processed, total, changed]));
						step(processed);
					}
				}).fail(fail);
			})(0);
		}).fail(fail);
	});
});
