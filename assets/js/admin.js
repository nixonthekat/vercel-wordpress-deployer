(function () {
	'use strict';

	var config = window.vercelWordPressDeployer || null;

	if (!config || !config.deployUrl || !config.statusUrl || !config.nonce) {
		return;
	}

	var labels = config.i18n || {};
	var region = null;
	var pollTimer = 0;
	var seenKey = '';
	var dismissedId = '';
	var ignoredId = '';
	var baselineReady = false;
	var inFlight = false;
	var seenServerAt = 0;
	var phases = {
		started: true,
		queued: true,
		building: true,
		succeeded: true,
		failed: true
	};

	function text(key, fallback) {
		var value = labels[key];

		if (typeof value === 'string' && value !== '') {
			return value;
		}

		return fallback;
	}

	function whitelistPhase(phase) {
		if (typeof phase === 'string' && Object.prototype.hasOwnProperty.call(phases, phase)) {
			return phase;
		}

		return 'failed';
	}

	function safeUrl(value) {
		var parsed = null;
		var host = '';

		if (typeof value !== 'string' || value.indexOf('https://') !== 0) {
			return '';
		}

		try {
			parsed = new URL(value);
		} catch (error) {
			return '';
		}

		if (parsed.protocol !== 'https:') {
			return '';
		}

		host = parsed.hostname.toLowerCase();

		if (host === 'vercel.com' || host.slice(-11) === '.vercel.app') {
			return parsed.href;
		}

		return '';
	}

	function ensureRegion() {
		if (region) {
			return region;
		}

		region = document.createElement('div');
		region.className = 'vwd-toasts';
		region.setAttribute('aria-live', 'polite');
		document.body.appendChild(region);

		return region;
	}

	function setBusy(isBusy) {
		var button = document.getElementById('vwd-deploy');
		var node = document.getElementById('wp-admin-bar-vercel-wordpress-deployer');

		if (button) {
			button.disabled = isBusy;
			button.setAttribute('aria-busy', isBusy ? 'true' : 'false');
		}

		if (node) {
			node.classList.toggle('vwd-is-busy', isBusy);
		}
	}

	function updateStatusLine(message, url) {
		var line = document.getElementById('vwd-status');
		var link = null;

		if (!line) {
			return;
		}

		line.hidden = false;
		line.textContent = message;

		if (url === '') {
			return;
		}

		line.appendChild(document.createTextNode(' '));
		link = document.createElement('a');
		link.href = url;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		link.textContent = text('see', 'See Deployment');
		line.appendChild(link);
	}

	function render(notice, phase, url) {
		var host = ensureRegion();
		var toast = document.createElement('div');
		var textNode = document.createElement('span');
		var closeButton = document.createElement('button');
		var spinner = null;
		var link = null;

		toast.className = 'vwd-toast vwd-toast--' + phase;
		toast.setAttribute('role', 'status');

		if (notice.watch && (phase === 'started' || phase === 'queued' || phase === 'building')) {
			spinner = document.createElement('span');
			spinner.className = 'vwd-toast__spin';
			spinner.setAttribute('aria-hidden', 'true');
			toast.appendChild(spinner);
		}

		textNode.className = 'vwd-toast__text';
		textNode.textContent = notice.message || '';
		toast.appendChild(textNode);

		if (url !== '') {
			link = document.createElement('a');
			link.className = 'vwd-toast__link';
			link.href = url;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.textContent = text('see', 'See Deployment');
			toast.appendChild(link);
		}

		closeButton.type = 'button';
		closeButton.className = 'vwd-toast__close';
		closeButton.setAttribute('aria-label', text('dismiss', 'Dismiss'));
		closeButton.textContent = '\u00d7';
		closeButton.addEventListener('click', function () {
			dismissedId = typeof notice.id === 'string' ? notice.id : '';
			toast.remove();
		});
		toast.appendChild(closeButton);

		host.replaceChildren(toast);
		updateStatusLine(notice.message || '', url);
	}

	function applyNotice(notice, fromUser) {
		var phase = '';
		var url = '';
		var age = 999;
		var id = '';
		var key = '';
		var watch = false;
		var serverAt = 0;

		if (inFlight && !fromUser) {
			return;
		}

		serverAt = notice && notice.updated_at ? Number(notice.updated_at) : 0;

		if (!fromUser && serverAt && seenServerAt && serverAt < seenServerAt) {
			return;
		}

		if (!notice || notice.phase === 'idle') {
			if (!fromUser && seenServerAt && (Date.now() / 1000) - seenServerAt < 30) {
				return;
			}

			if (!inFlight) {
				setBusy(false);
			}
			return;
		}

		id = typeof notice.id === 'string' ? notice.id : '';
		phase = whitelistPhase(notice.phase);
		url = safeUrl(notice.url);
		age = notice.updated_at ? (Date.now() / 1000) - Number(notice.updated_at) : 999;

		if (!baselineReady) {
			baselineReady = true;

			if (!notice.watch && age > 45) {
				ignoredId = id;
				return;
			}
		}

		if (ignoredId !== '' && id === ignoredId && !notice.watch) {
			return;
		}

		if (dismissedId !== '' && id === dismissedId && phase !== 'succeeded' && phase !== 'failed') {
			setBusy(!!notice.watch);
			return;
		}

		watch = !!notice.watch;
		key = id + '|' + phase + '|' + String(notice.message || '') + '|' + url + '|' + (watch ? '1' : '0');

		if (key === seenKey) {
			setBusy(!!notice.watch);
			return;
		}

		seenKey = key;

		if (serverAt && (fromUser || serverAt >= seenServerAt)) {
			if (typeof notice.id === 'string' && notice.id.indexOf('local-') !== 0) {
				seenServerAt = serverAt;
			}
		}

		render(notice, phase, url);
		setBusy(!!notice.watch || inFlight);
	}

	function schedule(delay) {
		window.clearTimeout(pollTimer);
		pollTimer = window.setTimeout(pullStatus, delay);
	}

	function pullStatus() {
		if (document.visibilityState === 'hidden') {
			schedule(4000);
			return;
		}

		window.fetch(config.statusUrl, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce
			}
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('status');
			}

			return response.json();
		}).then(function (notice) {
			applyNotice(notice, false);
			schedule(notice && notice.watch ? 3000 : 5000);
		}).catch(function () {
			schedule(15000);
		});
	}

	function startDeploy() {
		var localId = '';

		if (inFlight) {
			return;
		}

		inFlight = true;
		localId = 'local-' + String(Date.now());
		setBusy(true);

		applyNotice({
			id: localId,
			phase: 'started',
			message: text('started', 'Deployment started'),
			url: '',
			watch: true,
			updated_at: Date.now() / 1000
		}, true);

		window.fetch(config.deployUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json'
			},
			body: '{}'
		}).then(function (response) {
			return response.json().then(function (data) {
				return {
					ok: response.ok,
					data: data
				};
			});
		}).then(function (result) {
			var data = result.data || {};
			var message = '';

			inFlight = false;

			if (!result.ok) {
				message = typeof data.message === 'string' && data.message !== '' ? data.message : text('network', 'Deployment failed. The request did not complete.');
				applyNotice({
					id: localId,
					phase: 'failed',
					message: message,
					url: '',
					watch: false,
					updated_at: Date.now() / 1000
				}, true);
				setBusy(false);
				return;
			}

			if (!data.id) {
				data.id = localId;
			}

			applyNotice(data, true);
		}).catch(function () {
			inFlight = false;
			applyNotice({
				id: localId,
				phase: 'failed',
				message: text('network', 'Deployment failed. The request did not complete.'),
				url: '',
				watch: false,
				updated_at: Date.now() / 1000
			}, true);
			setBusy(false);
		});
	}

	document.addEventListener('click', function (event) {
		var target = event.target;
		var button = null;
		var deployLink = null;

		if (!(target instanceof Element)) {
			return;
		}

		button = target.closest('#vwd-deploy');
		deployLink = target.closest('#wp-admin-bar-vercel-wordpress-deployer-now > a.ab-item');

		if (!button && !deployLink) {
			return;
		}

		event.preventDefault();
		startDeploy();
	});

	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') {
			schedule(250);
		}
	});

	schedule(400);
}());
