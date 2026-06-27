(function () {
	'use strict';

	var COMMON_DNS_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT'];
	var ADVANCED_DNS_TYPES = ['CAA', 'SOA'];
	var SERVER_TYPES = ['HTTP', 'SSL', 'SERVER'];
	var TYPE_LABELS = {
		ALL: 'ALL DNS',
		A: 'A',
		AAAA: 'AAAA',
		CNAME: 'CNAME',
		MX: 'MX',
		NS: 'NS',
		TXT: 'TXT',
		CAA: 'CAA',
		SOA: 'SOA',
		HTTP: 'HTTP',
		SSL: 'SSL',
		SERVER: 'SERVER'
	};

	function DpsDnsLookupWidget(root) {
		this.root = root;
		this.config = window.dpsDnsLookupConfig || {};
		this.limit = parseInt(root.getAttribute('data-limit'), 10) || 100;
		this.defaultDelay = parseInt(root.getAttribute('data-delay'), 10) || 120;
		this.activeTypes = {};
		this.abort = false;
		this.running = false;
		this.tableData = [];
		this.rowMap = {};

		// Translation texts from container attributes
		this.tTxtAllDns = root.getAttribute('data-txt-all-dns') || 'ALL DNS';
		this.tTxtStopping = root.getAttribute('data-txt-stopping') || 'Đang dừng...';
		this.tTxtEnterDomain = root.getAttribute('data-txt-enter-domain') || 'Vui lòng nhập ít nhất một tên miền.';
		this.tTxtSelectColumn = root.getAttribute('data-txt-select-column') || 'Vui lòng chọn ít nhất một cột cần kiểm tra.';
		this.tTxtLimitExceeded = root.getAttribute('data-txt-limit-exceeded') || 'Danh sách vượt giới hạn %s tên miền. Hãy chia nhỏ danh sách.';
		this.tTxtMissingConfig = root.getAttribute('data-txt-missing-config') || 'Thiếu cấu hình REST endpoint.';
		this.tTxtStoppedAt = root.getAttribute('data-txt-stopped-at') || 'Đã dừng tại %s/%s.';
		this.tTxtCompleted = root.getAttribute('data-txt-completed') || 'Hoàn thành: %s domain x %s cột.';
		this.tTxtNoDataCopy = root.getAttribute('data-txt-no-data-copy') || 'Không có dữ liệu để sao chép.';
		this.tTxtCopied = root.getAttribute('data-txt-copied') || 'Đã sao chép TSV vào clipboard.';
		this.tTxtCopyFailed = root.getAttribute('data-txt-copy-failed') || 'Sao chép thất bại: %s';
		this.tTxtReady = root.getAttribute('data-txt-ready') || 'Sẵn sàng tra cứu DNS & server';
		this.tTxtEmptySubtext = root.getAttribute('data-txt-empty-subtext') || 'Mỗi domain là một dòng, mỗi loại kiểm tra là một cột.';
		this.tTxtDomain = root.getAttribute('data-txt-domain') || 'Domain';
		this.tTxtEmpty = root.getAttribute('data-txt-empty') || 'Empty';
		this.tTxtError = root.getAttribute('data-txt-error') || 'Error';

		this.bindElements();
		this.seedDefaultTypes();
		this.renderTypes();
		this.bindEvents();
		this.updateDomainCount();
		this.setProgress(0, 0);
	}

	DpsDnsLookupWidget.prototype.bindElements = function () {
		this.textarea = this.root.querySelector('.dps-dns-textarea');
		this.count = this.root.querySelector('.dps-dns-count');
		this.types = this.root.querySelector('.dps-dns-types');
		this.delay = this.root.querySelector('.dps-dns-delay-field');
		this.runButton = this.root.querySelector('[data-action="run"]');
		this.stopButton = this.root.querySelector('[data-action="stop"]');
		this.copyButton = this.root.querySelector('[data-action="copy"]');
		this.clearButton = this.root.querySelector('[data-action="clear"]');
		this.status = this.root.querySelector('.dps-dns-status');
		this.error = this.root.querySelector('.dps-dns-error');
		this.results = this.root.querySelector('.dps-dns-results');
		this.progress = this.root.querySelector('.dps-dns-progress span');
	};

	DpsDnsLookupWidget.prototype.seedDefaultTypes = function () {
		this.activeTypes.A = true;
		if (this.isToolEnabled('HTTP')) {
			this.activeTypes.HTTP = true;
		}
		if (this.isToolEnabled('SSL')) {
			this.activeTypes.SSL = true;
		}
		if (this.isToolEnabled('SERVER')) {
			this.activeTypes.SERVER = true;
		}
	};

	DpsDnsLookupWidget.prototype.isToolEnabled = function (type) {
		if (SERVER_TYPES.indexOf(type) === -1) {
			return true;
		}

		if (!this.config.enabledTools) {
			return true;
		}

		return this.config.enabledTools[type] !== false;
	};

	DpsDnsLookupWidget.prototype.getAvailableTypes = function () {
		var widget = this;
		return ['ALL'].concat(COMMON_DNS_TYPES, ADVANCED_DNS_TYPES, SERVER_TYPES.filter(function (type) {
			return widget.isToolEnabled(type);
		}));
	};

	DpsDnsLookupWidget.prototype.getSelectedTypes = function () {
		var order = COMMON_DNS_TYPES.concat(ADVANCED_DNS_TYPES, SERVER_TYPES);
		var widget = this;
		return order.filter(function (type) {
			return Boolean(widget.activeTypes[type]) && widget.isToolEnabled(type);
		});
	};

	DpsDnsLookupWidget.prototype.renderTypes = function () {
		var widget = this;
		var allCommonSelected = COMMON_DNS_TYPES.every(function (type) {
			return Boolean(widget.activeTypes[type]);
		});

		this.types.textContent = '';

		this.getAvailableTypes().forEach(function (type) {
			var button = document.createElement('button');
			var isActive = type === 'ALL' ? allCommonSelected : Boolean(widget.activeTypes[type]);

			button.type = 'button';
			button.className = 'dps-dns-type';
			button.textContent = type === 'ALL' ? widget.tTxtAllDns : (TYPE_LABELS[type] || type);
			button.setAttribute('aria-pressed', isActive ? 'true' : 'false');

			if (isActive) {
				button.classList.add('is-active');
			}

			if (SERVER_TYPES.indexOf(type) !== -1) {
				button.classList.add('dps-dns-type-server');
			}

			button.addEventListener('click', function () {
				if (widget.running) {
					return;
				}

				if (type === 'ALL') {
					if (COMMON_DNS_TYPES.every(function (item) { return Boolean(widget.activeTypes[item]); })) {
						COMMON_DNS_TYPES.forEach(function (item) {
							delete widget.activeTypes[item];
						});
					} else {
						COMMON_DNS_TYPES.forEach(function (item) {
							widget.activeTypes[item] = true;
						});
					}
				} else if (widget.activeTypes[type]) {
					delete widget.activeTypes[type];
				} else {
					widget.activeTypes[type] = true;
				}

				widget.renderTypes();
			});

			widget.types.appendChild(button);
		});
	};

	DpsDnsLookupWidget.prototype.bindEvents = function () {
		var widget = this;

		this.textarea.addEventListener('input', function () {
			widget.updateDomainCount();
		});

		this.delay.addEventListener('input', function () {
			widget.clampDelay();
		});

		this.delay.addEventListener('keydown', function (event) {
			if (event.key === '-') {
				event.preventDefault();
			}
		});

		this.runButton.addEventListener('click', function () {
			widget.run();
		});

		this.stopButton.addEventListener('click', function () {
			widget.abort = true;
			widget.setStatus(widget.tTxtStopping);
		});

		this.clearButton.addEventListener('click', function () {
			if (!widget.running) {
				widget.clearResults();
			}
		});

		this.copyButton.addEventListener('click', function () {
			widget.copyRows();
		});
	};

	DpsDnsLookupWidget.prototype.extractHostname = function (value) {
		var cleaned = String(value || '').trim();
		var parsed;

		if (!cleaned) {
			return '';
		}

		try {
			parsed = new URL(cleaned);
			return parsed.hostname.replace(/\.$/, '').toLowerCase();
		} catch (error) {
			return cleaned
				.replace(/^https?:\/\//i, '')
				.replace(/^\/+/, '')
				.split('/')[0]
				.split('?')[0]
				.split('#')[0]
				.replace(/\.$/, '')
				.toLowerCase();
		}
	};

	DpsDnsLookupWidget.prototype.getDomains = function () {
		var widget = this;
		var seen = {};
		var domains = [];

		this.textarea.value.split(/\r?\n/).forEach(function (line) {
			var domain = widget.extractHostname(line);
			if (domain && !seen[domain]) {
				seen[domain] = true;
				domains.push(domain);
			}
		});

		return domains;
	};

	DpsDnsLookupWidget.prototype.updateDomainCount = function () {
		var total = this.getDomains().length;
		this.count.textContent = String(total) + '/' + String(this.limit);
		this.count.classList.toggle('is-warning', total > Math.floor(this.limit * 0.7));
		this.count.classList.toggle('is-danger', total > this.limit);
	};

	DpsDnsLookupWidget.prototype.clampDelay = function () {
		var value = parseInt(this.delay.value, 10);

		if (!Number.isFinite(value) || value < 50) {
			value = 50;
		}

		if (value > 5000) {
			value = 5000;
		}

		this.delay.value = String(value);
		return value;
	};

	DpsDnsLookupWidget.prototype.setStatus = function (message) {
		this.status.textContent = message || '';
	};

	DpsDnsLookupWidget.prototype.showError = function (message) {
		this.error.textContent = message || '';
		this.error.hidden = !message;
	};

	DpsDnsLookupWidget.prototype.setProgress = function (current, total) {
		var percent = total ? Math.min((current / total) * 100, 100) : 0;
		this.progress.style.width = String(percent) + '%';
	};

	DpsDnsLookupWidget.prototype.setRunning = function (running) {
		this.running = running;
		this.runButton.disabled = running;
		this.stopButton.hidden = !running;
		this.clearButton.disabled = running;
		this.textarea.disabled = running;
		this.delay.disabled = running;

		Array.prototype.forEach.call(this.types.children, function (button) {
			button.disabled = running;
		});
	};

	DpsDnsLookupWidget.prototype.clearResults = function () {
		this.tableData = [];
		this.rowMap = {};
		this.copyButton.disabled = true;
		this.showError('');
		this.setStatus('');
		this.setProgress(0, 0);
		this.renderEmptyState();
	};

	DpsDnsLookupWidget.prototype.renderEmptyState = function () {
		var empty = document.createElement('div');
		var icon = document.createElement('div');
		var title = document.createElement('div');
		var copy = document.createElement('div');

		empty.className = 'dps-dns-empty';
		icon.className = 'dps-dns-empty-icon';
		icon.setAttribute('aria-hidden', 'true');
		icon.textContent = 'Lookup';
		title.className = 'dps-dns-empty-title';
		title.textContent = this.tTxtReady;
		copy.className = 'dps-dns-empty-copy';
		copy.textContent = this.tTxtEmptySubtext;

		empty.appendChild(icon);
		empty.appendChild(title);
		empty.appendChild(copy);
		this.results.textContent = '';
		this.results.appendChild(empty);
	};

	DpsDnsLookupWidget.prototype.initPivotTable = function (domains, types) {
		var widget = this;
		var table = document.createElement('div');
		var head = document.createElement('div');
		var body = document.createElement('div');
		var gridTemplate = 'minmax(170px, 1.25fr) ' + types.map(function () {
			return 'minmax(190px, 1fr)';
		}).join(' ');

		this.tableData = [];
		this.rowMap = {};
		this.results.textContent = '';

		table.className = 'dps-dns-table dps-dns-table-pivot';
		head.className = 'dps-dns-table-head';
		head.style.gridTemplateColumns = gridTemplate;
		body.className = 'dps-dns-table-body';

		this.addHeaderCell(head, this.tTxtDomain);
		types.forEach(function (type) {
			widget.addHeaderCell(head, TYPE_LABELS[type] || type);
		});

		domains.forEach(function (domain) {
			var row = document.createElement('div');
			var domainCell = document.createElement('div');
			var cells = {};
			var dataRow = {
				domain: domain,
				results: {}
			};

			row.className = 'dps-dns-table-row';
			row.style.gridTemplateColumns = gridTemplate;

			domainCell.className = 'dps-dns-table-cell dps-dns-cell-domain';
			domainCell.textContent = domain;
			row.appendChild(domainCell);

			types.forEach(function (type) {
				var cell = document.createElement('div');
				cell.className = 'dps-dns-table-cell dps-dns-pivot-cell';
				widget.renderLoadingCell(cell);
				row.appendChild(cell);
				cells[type] = cell;
				dataRow.results[type] = '';
			});

			widget.tableData.push(dataRow);
			widget.rowMap[domain] = {
				cells: cells,
				data: dataRow
			};
			body.appendChild(row);
		});

		table.appendChild(head);
		table.appendChild(body);
		this.results.appendChild(table);
	};

	DpsDnsLookupWidget.prototype.addHeaderCell = function (head, label) {
		var cell = document.createElement('div');
		cell.className = 'dps-dns-table-cell';
		cell.textContent = label;
		head.appendChild(cell);
	};

	DpsDnsLookupWidget.prototype.renderLoadingCell = function (cell) {
		cell.textContent = '';
		var badge = document.createElement('span');
		badge.className = 'dps-dns-badge dps-dns-badge-neutral';
		badge.textContent = '...';
		cell.appendChild(badge);
	};

	DpsDnsLookupWidget.prototype.renderLookupCell = function (cell, type, payload, cached) {
		var rows = payload.rows || [];
		var textValue = this.rowsToDisplayText(rows);

		cell.textContent = '';

		if (!rows.length) {
			this.appendBadge(cell, this.tTxtEmpty, 'neutral');
			return this.tTxtEmpty;
		}

		if (type === 'HTTP') {
			this.renderHttpCell(cell, rows[0], cached);
			return textValue;
		}

		if (type === 'SSL') {
			this.renderSslCell(cell, rows[0], cached);
			return textValue;
		}

		if (type === 'SERVER') {
			this.renderServerCell(cell, rows[0], cached);
			return textValue;
		}

		this.renderDnsCell(cell, rows, cached);
		return textValue;
	};

	DpsDnsLookupWidget.prototype.renderHttpCell = function (cell, row) {
		var code = parseInt(row.data, 10);
		var tone = 'neutral';

		if (code >= 200 && code < 300) {
			tone = 'success';
		} else if (code >= 300 && code < 400) {
			tone = 'warning';
		} else if (code >= 400 || String(row.data || '').toLowerCase() === 'error') {
			tone = 'error';
		}

		this.appendBadge(cell, row.data || '', tone);
	};

	DpsDnsLookupWidget.prototype.renderSslCell = function (cell, row) {
		var days = parseInt(row.data, 10);
		var tone = 'success';

		if (!Number.isFinite(days)) {
			tone = 'error';
		} else if (days < 0) {
			tone = 'error';
		} else if (days < 14) {
			tone = 'warning';
		}

		this.appendBadge(cell, row.data || '', tone);
	};

	DpsDnsLookupWidget.prototype.renderServerCell = function (cell, row) {
		this.appendBadge(cell, row.data || '', row.data ? 'neutral' : 'error');
	};

	DpsDnsLookupWidget.prototype.renderDnsCell = function (cell, rows) {
		var block = document.createElement('div');
		var row = rows[0] || {};

		block.className = 'dps-dns-code-block';
		block.textContent = row.data || '';
		cell.appendChild(block);
	};

	DpsDnsLookupWidget.prototype.appendBadge = function (cell, label, tone) {
		var badge = document.createElement('span');
		badge.className = 'dps-dns-badge dps-dns-badge-' + tone;
		badge.textContent = label;
		cell.appendChild(badge);
	};

	DpsDnsLookupWidget.prototype.rowsToDisplayText = function (rows) {
		return rows.length ? String(rows[0].data || '') : '';
	};

	DpsDnsLookupWidget.prototype.refreshNonce = function () {
		var widget = this;

		if (!this.config.nonceUrl) {
			return Promise.reject(new Error('Thiếu endpoint làm mới phiên bảo mật.'));
		}

		return window.fetch(this.config.nonceUrl, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store'
		}).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok || !payload || !payload.nonce) {
					throw new Error('Không thể làm mới phiên bảo mật.');
				}
				widget.config.nonce = payload.nonce;
				window.dpsDnsLookupConfig = widget.config;
				return payload.nonce;
			});
		});
	};

	DpsDnsLookupWidget.prototype.lookup = function (domain, type, didRefreshNonce) {
		var widget = this;
		var form = new window.FormData();
		form.append('domain', domain);
		form.append('type', type);
		form.append('nonce', this.config.nonce || '');

		return window.fetch(this.config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			body: form
		}).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok) {
					if (payload && payload.code === 'dps_dns_lookup_bad_nonce' && !didRefreshNonce) {
						return widget.refreshNonce().then(function () {
							return widget.lookup(domain, type, true);
						});
					}
					throw new Error(payload && payload.message ? payload.message : 'Lookup failed');
				}
				return payload;
			});
		});
	};

	DpsDnsLookupWidget.prototype.sleep = function (ms) {
		return new Promise(function (resolve) {
			window.setTimeout(resolve, ms);
		});
	};

	DpsDnsLookupWidget.prototype.run = async function () {
		var domains;
		var types;
		var delay;
		var total;
		var done = 0;
		var i;
		var j;
		var domain;
		var type;
		var result;
		var cell;
		var displayText;

		if (this.running) {
			return;
		}

		this.showError('');
		domains = this.getDomains();
		types = this.getSelectedTypes();

		if (!domains.length) {
			this.showError(this.tTxtEnterDomain);
			return;
		}

		if (!types.length) {
			this.showError(this.tTxtSelectColumn);
			return;
		}

		if (domains.length > this.limit) {
			this.showError(this.tTxtLimitExceeded.replace('%s', String(this.limit)));
			return;
		}

		if (!this.config.restUrl) {
			this.showError(this.tTxtMissingConfig);
			return;
		}

		this.abort = false;
		this.copyButton.disabled = true;
		delay = this.clampDelay();
		total = domains.length * types.length;
		this.initPivotTable(domains, types);
		this.setRunning(true);
		this.setProgress(0, total);

		for (i = 0; i < domains.length; i += 1) {
			domain = domains[i];
			for (j = 0; j < types.length; j += 1) {
				type = types[j];
				cell = this.rowMap[domain].cells[type];

				if (this.abort) {
					break;
				}

				this.setStatus(domain + ' [' + type + '] ' + String(done + 1) + '/' + String(total));
				this.renderLoadingCell(cell);

				try {
					result = await this.lookup(domain, type);
					displayText = this.renderLookupCell(cell, type, result, Boolean(result.cached));
					this.rowMap[domain].data.results[type] = displayText;
				} catch (error) {
					cell.textContent = '';
					this.appendBadge(cell, this.tTxtError, 'error');
					this.rowMap[domain].data.results[type] = this.tTxtError;
				}

				done += 1;
				this.setProgress(done, total);

				if (done < total && !this.abort) {
					await this.sleep(delay);
				}
			}

			if (this.abort) {
				break;
			}
		}

		if (this.abort) {
			this.setStatus(this.tTxtStoppedAt.replace('%s', String(done)).replace('%s', String(total)));
		} else {
			this.setStatus(this.tTxtCompleted.replace('%s', String(domains.length)).replace('%s', String(types.length)));
		}

		this.copyButton.disabled = this.tableData.length === 0;
		this.setRunning(false);
	};

	DpsDnsLookupWidget.prototype.rowsToTsv = function () {
		var selectedTypes = this.getSelectedTypes();
		var lines = [[this.tTxtDomain].concat(selectedTypes).join('\t')];

		this.tableData.forEach(function (row) {
			var cells = [row.domain];
			selectedTypes.forEach(function (type) {
				cells.push(row.results[type] || '');
			});
			lines.push(cells.map(function (value) {
				var text = String(value == null ? '' : value);
				if (text.indexOf('\n') !== -1 || text.indexOf('\t') !== -1 || text.indexOf('"') !== -1) {
					return '"' + text.replace(/"/g, '""') + '"';
				}
				return text;
			}).join('\t'));
		});

		return lines.join('\n');
	};

	DpsDnsLookupWidget.prototype.copyRows = function () {
		var widget = this;
		var text = this.rowsToTsv();

		if (!this.tableData.length) {
			this.setStatus(this.tTxtNoDataCopy);
			return;
		}

		function done() {
			widget.setStatus(widget.tTxtCopied);
		}

		if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
			window.navigator.clipboard.writeText(text).then(done).catch(function (error) {
				widget.setStatus(widget.tTxtCopyFailed.replace('%s', error.message));
			});
			return;
		}

		var textarea = document.createElement('textarea');
		textarea.value = text;
		textarea.setAttribute('readonly', 'readonly');
		textarea.style.position = 'fixed';
		textarea.style.left = '-9999px';
		document.body.appendChild(textarea);
		textarea.select();
		document.execCommand('copy');
		document.body.removeChild(textarea);
		done();
	};

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('.dps-dns-widget'), function (root) {
			if (!root.dpsDnsLookupWidget) {
				root.dpsDnsLookupWidget = new DpsDnsLookupWidget(root);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
