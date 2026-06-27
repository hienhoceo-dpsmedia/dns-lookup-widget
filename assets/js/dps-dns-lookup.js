(function () {
	'use strict';

	var POPULAR_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT'];
	var RECORD_TYPES = ['ALL', 'A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'CAA', 'SOA'];
	var TYPE_LABELS = {
		ALL: 'ALL',
		A: 'A',
		AAAA: 'AAAA',
		CNAME: 'CNAME',
		MX: 'MX',
		NS: 'NS',
		TXT: 'TXT',
		CAA: 'CAA',
		SOA: 'SOA'
	};

	function DpsDnsLookupWidget(root) {
		this.root = root;
		this.config = window.dpsDnsLookupConfig || {};
		this.limit = parseInt(root.getAttribute('data-limit'), 10) || 100;
		this.defaultDelay = parseInt(root.getAttribute('data-delay'), 10) || 120;
		this.activeType = 'A';
		this.abort = false;
		this.running = false;
		this.rows = [];
		this.bindElements();
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

	DpsDnsLookupWidget.prototype.renderTypes = function () {
		var widget = this;
		this.types.textContent = '';

		RECORD_TYPES.forEach(function (type) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'dps-dns-type';
			button.textContent = TYPE_LABELS[type] || type;
			button.setAttribute('aria-pressed', type === widget.activeType ? 'true' : 'false');

			if (type === widget.activeType) {
				button.classList.add('is-active');
			}

			button.addEventListener('click', function () {
				if (widget.running) {
					return;
				}
				widget.activeType = type;
				Array.prototype.forEach.call(widget.types.children, function (child) {
					child.classList.remove('is-active');
					child.setAttribute('aria-pressed', 'false');
				});
				button.classList.add('is-active');
				button.setAttribute('aria-pressed', 'true');
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
			widget.setStatus('Dang dung...');
		});

		this.clearButton.addEventListener('click', function () {
			if (widget.running) {
				return;
			}
			widget.clearResults();
		});

		this.copyButton.addEventListener('click', function () {
			widget.copyRows();
		});
	};

	DpsDnsLookupWidget.prototype.extractHostname = function (value) {
		var cleaned = String(value || '').trim();
		var anchor;

		if (!cleaned) {
			return '';
		}

		try {
			anchor = new URL(cleaned);
			return anchor.hostname.replace(/\.$/, '').toLowerCase();
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
		this.rows = [];
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
		title.textContent = 'San sang tra cuu DNS';
		copy.className = 'dps-dns-empty-copy';
		copy.textContent = 'Ket qua se hien thi tai day sau khi chay cong cu.';

		empty.appendChild(icon);
		empty.appendChild(title);
		empty.appendChild(copy);
		this.results.textContent = '';
		this.results.appendChild(empty);
	};

	DpsDnsLookupWidget.prototype.ensureTable = function () {
		var table;
		var head;
		var body;

		table = this.results.querySelector('.dps-dns-table');
		if (table) {
			return table.querySelector('.dps-dns-table-body');
		}

		this.results.textContent = '';
		table = document.createElement('div');
		table.className = 'dps-dns-table';
		head = document.createElement('div');
		head.className = 'dps-dns-table-head';
		body = document.createElement('div');
		body.className = 'dps-dns-table-body';

		[
			['Domain', ''],
			['Type', ''],
			['Name', 'dps-dns-head-name'],
			['TTL', ''],
			['Data', ''],
			['Cache', 'dps-dns-head-cache']
		].forEach(function (cell) {
			var item = document.createElement('div');
			item.className = 'dps-dns-table-cell ' + cell[1];
			item.textContent = cell[0];
			head.appendChild(item);
		});

		table.appendChild(head);
		table.appendChild(body);
		this.results.appendChild(table);

		return body;
	};

	DpsDnsLookupWidget.prototype.appendRows = function (rows, cached) {
		var widget = this;
		var body = this.ensureTable();
		var fragment = document.createDocumentFragment();

		rows.forEach(function (row) {
			var record = {
				domain: row.domain || '',
				type: row.type || '',
				name: row.name || '',
				ttl: row.ttl || '',
				data: row.data || '',
				cached: cached ? 'Yes' : 'No'
			};
			var tr = document.createElement('div');
			tr.className = 'dps-dns-table-row';

			[
				['domain', ''],
				['type', 'dps-dns-cell-type'],
				['name', 'dps-dns-cell-name'],
				['ttl', 'dps-dns-cell-ttl'],
				['data', 'dps-dns-cell-data'],
				['cached', 'dps-dns-cell-cache']
			].forEach(function (cell) {
				var item = document.createElement('div');
				item.className = 'dps-dns-table-cell ' + cell[1];
				item.textContent = record[cell[0]];
				if (cell[0] === 'data' && /^Error:/i.test(record.data)) {
					item.classList.add('dps-dns-cell-error');
				}
				tr.appendChild(item);
			});

			widget.rows.push(record);
			fragment.appendChild(tr);
		});

		body.appendChild(fragment);
		this.copyButton.disabled = this.rows.length === 0;
	};

	DpsDnsLookupWidget.prototype.lookup = function (domain, type) {
		var form = new window.FormData();
		form.append('domain', domain);
		form.append('type', type);
		form.append('nonce', this.config.nonce || '');

		return window.fetch(this.config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		}).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok) {
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

		if (this.running) {
			return;
		}

		this.showError('');
		domains = this.getDomains();

		if (!domains.length) {
			this.showError('Vui long nhap it nhat mot ten mien.');
			return;
		}

		if (domains.length > this.limit) {
			this.showError('Danh sach vuot gioi han ' + String(this.limit) + ' ten mien. Hay chia nho danh sach.');
			return;
		}

		if (!this.config.restUrl) {
			this.showError('Thieu cau hinh REST endpoint.');
			return;
		}

		this.abort = false;
		this.rows = [];
		this.results.textContent = '';
		this.copyButton.disabled = true;
		delay = this.clampDelay();
		types = this.activeType === 'ALL' ? POPULAR_TYPES.slice() : [this.activeType];
		total = domains.length * types.length;
		this.setRunning(true);
		this.setProgress(0, total);

		for (i = 0; i < domains.length; i += 1) {
			domain = domains[i];
			for (j = 0; j < types.length; j += 1) {
				type = types[j];

				if (this.abort) {
					break;
				}

				this.setStatus(domain + ' [' + type + '] ' + String(done + 1) + '/' + String(total));

				try {
					result = await this.lookup(domain, type);
					this.appendRows(result.rows || [], Boolean(result.cached));
				} catch (error) {
					this.appendRows([
						{
							domain: domain,
							type: type,
							name: '',
							ttl: '',
							data: 'Error: ' + error.message
						}
					], false);
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
			this.setStatus('Da dung tai ' + String(done) + '/' + String(total) + '.');
		} else {
			this.setStatus('Hoan thanh: ' + String(this.rows.length) + ' dong ket qua.');
		}

		this.setRunning(false);
	};

	DpsDnsLookupWidget.prototype.rowsToTsv = function () {
		var lines = [['Domain', 'Type', 'Name', 'TTL', 'Data', 'Cache'].join('\t')];
		this.rows.forEach(function (row) {
			lines.push([
				row.domain,
				row.type,
				row.name,
				row.ttl,
				row.data,
				row.cached
			].map(function (value) {
				return String(value == null ? '' : value).replace(/\t/g, ' ').replace(/\r?\n/g, ' ');
			}).join('\t'));
		});
		return lines.join('\n');
	};

	DpsDnsLookupWidget.prototype.copyRows = function () {
		var widget = this;
		var text = this.rowsToTsv();

		if (!this.rows.length) {
			this.setStatus('Khong co du lieu de sao chep.');
			return;
		}

		function done() {
			widget.setStatus('Da sao chep TSV vao clipboard.');
		}

		if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
			window.navigator.clipboard.writeText(text).then(done).catch(function (error) {
				widget.setStatus('Sao chep that bai: ' + error.message);
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
