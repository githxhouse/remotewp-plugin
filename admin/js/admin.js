/**
 * RemoteWP Admin JavaScript
 *
 * Handles clipboard copy, secure token hide/reveal, 
 * token regeneration confirmation, and inline connection testing.
 *
 * @package RemoteWP
 */

(function () {
	'use strict';

	function initAdminUI() {
		initNoticeSanitizer();
		initTokenReveal();
		initCopyButtons();
		initRegenConfirmation();
		initConnectionTest();
	}

	// Run reliably even if this script is deferred/optimized and loaded
	// after DOMContentLoaded has already fired.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAdminUI);
	} else {
		initAdminUI();
	}

	/**
	 * Remove third-party promotional/admin notices injected into RemoteWP UI.
	 *
	 * Some plugins append "notice" blocks directly via JavaScript or custom hooks,
	 * bypassing normal admin_notices filtering. We sanitize only inside the
	 * RemoteWP wrapper and preserve RemoteWP-native notices.
	 */
	function initNoticeSanitizer() {
		var wrap = document.querySelector('.rwp-admin.remotewp-wrap');
		if (!wrap) {
			return;
		}

		var selector = 'div.notice, div.update-nag, div.updated, div.error';

		function isRemoteWpNotice(node) {
			if (!node || !node.classList) {
				return false;
			}
			if (node.classList.contains('remotewp-notice')) {
				return true;
			}
			return !!node.closest('.remotewp-notice');
		}

		function purgeForeignNotices(rootNode) {
			if (!rootNode || !rootNode.querySelectorAll) {
				return;
			}
			rootNode.querySelectorAll(selector).forEach(function (node) {
				if (isRemoteWpNotice(node)) {
					return;
				}
				node.remove();
			});
		}

		// Initial cleanup.
		purgeForeignNotices(wrap);

		// Keep removing notices injected later by third-party scripts.
		var observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(function (addedNode) {
					if (!addedNode || addedNode.nodeType !== 1) {
						return;
					}
					if (addedNode.matches && addedNode.matches(selector) && !isRemoteWpNotice(addedNode)) {
						addedNode.remove();
						return;
					}
					purgeForeignNotices(addedNode);
				});
			});
		});

		observer.observe(wrap, { childList: true, subtree: true });
	}

	/**
	 * Secure Token Hide/Reveal toggle.
	 */
	function initTokenReveal() {
		var tokenInput = document.getElementById('remotewp-token');
		var revealBtn = document.getElementById('remotewp-btn-reveal');

		if (!tokenInput || !revealBtn) {
			return;
		}

		revealBtn.addEventListener('click', function (e) {
			e.preventDefault();
			var textSpan = revealBtn.querySelector('.rwp-btn-text');
			var iconSpan = revealBtn.querySelector('.dashicons');

			if (tokenInput.type === 'password') {
				tokenInput.type = 'text';
				if (textSpan) textSpan.textContent = 'Hide';
				if (iconSpan) {
					iconSpan.classList.remove('dashicons-visibility');
					iconSpan.classList.add('dashicons-hidden');
				}
			} else {
				tokenInput.type = 'password';
				if (textSpan) textSpan.textContent = 'Reveal';
				if (iconSpan) {
					iconSpan.classList.remove('dashicons-hidden');
					iconSpan.classList.add('dashicons-visibility');
				}
			}
		});
	}

	/**
	 * Initialize all copy-to-clipboard buttons.
	 */
	function initCopyButtons() {
		var buttons = document.querySelectorAll('.remotewp-btn-copy, .remotewp-btn-copy-small');

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				var targetId = btn.getAttribute('data-target');
				var target = document.getElementById(targetId);

				if (!target) {
					return;
				}

				var text = target.value || target.textContent;

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function () {
						showCopyFeedback(btn, true);
					}).catch(function () {
						fallbackCopy(target, btn);
					});
				} else {
					fallbackCopy(target, btn);
				}
			});
		});
	}

	/**
	 * Fallback copy using select + execCommand.
	 *
	 * @param {HTMLElement} target The element to copy from.
	 * @param {HTMLElement} btn    The button that was clicked.
	 */
	function fallbackCopy(target, btn) {
		if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
			target.select();
			target.setSelectionRange(0, 99999);
		} else {
			var range = document.createRange();
			range.selectNodeContents(target);
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange(range);
		}

		try {
			document.execCommand('copy');
			showCopyFeedback(btn, true);
		} catch (err) {
			showCopyFeedback(btn, false);
		}
	}

	/**
	 * Show visual feedback on copy.
	 *
	 * @param {HTMLElement} btn     The button element.
	 * @param {boolean}     success Whether copy succeeded.
	 */
	function showCopyFeedback(btn, success) {
		var originalHTML = btn.innerHTML;
		var message = success ? 'Copied' : 'Failed';

		btn.innerHTML = '<span class="dashicons dashicons-' + (success ? 'yes' : 'no') + '"></span> ' + message;
		btn.classList.add('copied');

		setTimeout(function () {
			btn.innerHTML = originalHTML;
			btn.classList.remove('copied');
		}, 1500);
	}

	/**
	 * Initialize token regeneration confirmation.
	 */
	function initRegenConfirmation() {
		var regenBtn = document.getElementById('remotewp-regen-btn');
		if (!regenBtn) {
			return;
		}

		regenBtn.addEventListener('click', function (e) {
			var message = 'Are you sure? Existing integrations using the current token will stop working.';
			if (!confirm(message)) {
				e.preventDefault();
			}
		});
	}

	/**
	 * Initialize connection tester.
	 */
	function initConnectionTest() {
		var testBtn = document.getElementById('rwp-btn-test-connection');
		var resultDiv = document.getElementById('rwp-test-result');

		if (!testBtn || !resultDiv) {
			return;
		}

		testBtn.addEventListener('click', function (e) {
			e.preventDefault();
			
			var tokenInput = document.getElementById('remotewp-token');
			if (!tokenInput) return;

			var token = tokenInput.value;
			var restUrl = window.remotewpAdmin ? window.remotewpAdmin.restUrl : '';

			if (!restUrl) {
				resultDiv.className = 'rwp-test-result-error';
				resultDiv.textContent = 'REST API endpoint URL not found.';
				return;
			}

			// Show loading state
			testBtn.disabled = true;
			var originalHTML = testBtn.innerHTML;
			testBtn.innerHTML = '<span class="dashicons dashicons-update rwp-spin"></span> Testing...';
			
			resultDiv.className = 'rwp-test-result-loading';
			resultDiv.textContent = 'Connecting to server API...';

			var startTime = performance.now();

			fetch(restUrl + 'status', {
				method: 'GET',
				headers: {
					'X-RemoteWP-Token': token,
					'Content-Type': 'application/json'
				}
			})
			.then(function (response) {
				var endTime = performance.now();
				var elapsed = Math.round(endTime - startTime);

				if (response.ok) {
					resultDiv.className = 'rwp-test-result-success';
					resultDiv.innerHTML = '<span class="dashicons dashicons-yes"></span> Connection successful · ' + elapsed + 'ms';
				} else {
					return response.json().then(function (err) {
						throw new Error(err.message || 'Server returned status ' + response.status);
					}).catch(function () {
						throw new Error('Server returned status ' + response.status);
					});
				}
			})
			.catch(function (error) {
				resultDiv.className = 'rwp-test-result-error';
				resultDiv.textContent = '';
				var errIcon = document.createElement('span');
				errIcon.className = 'dashicons dashicons-no';
				resultDiv.appendChild(errIcon);
				resultDiv.appendChild(document.createTextNode(' Connection failed: ' + error.message));
			})
			.finally(function () {
				testBtn.disabled = false;
				testBtn.innerHTML = originalHTML;
			});
		});
	}
})();
