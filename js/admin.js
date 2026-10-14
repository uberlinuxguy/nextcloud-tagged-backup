/**
 * Admin settings UI for Tagged Backup. Plain DOM code, no build step.
 */
(function () {
	'use strict'

	const DESTINATIONS = {
		s3: {
			label: 'S3 compatible storage',
			fields: [
				{ key: 'endpoint', label: 'Endpoint URL', placeholder: 'https://s3.amazonaws.com', hint: 'Leave empty for AWS. For MinIO, Backblaze B2, Wasabi, R2 etc. use the provider\'s S3 endpoint.' },
				{ key: 'region', label: 'Region', placeholder: 'us-east-1' },
				{ key: 'bucket', label: 'Bucket', required: true },
				{ key: 'prefix', label: 'Folder (key prefix)', placeholder: 'nextcloud-backups' },
				{ key: 'access_key', label: 'Access key ID', required: true },
				{ key: 'secret_key', label: 'Secret access key', type: 'secret' },
				{ key: 'storage_class', label: 'Storage class', placeholder: 'optional, e.g. STANDARD_IA' },
				{ key: 'path_style', label: 'Use path-style URLs (needed by MinIO and most self-hosted servers)', type: 'bool', default: true },
				{ key: 'allow_local', label: 'Endpoint is on a local or private network', type: 'bool' },
			],
		},
		gdrive: {
			label: 'Google Drive OAuth',
			oauth: true,
			help: 'In the Google Cloud console, enable the Google Drive API, then create an OAuth client ID of type "Web application" with the redirect URI below. Backups are placed in a folder the app creates (it only gets access to files it created itself).',
			fields: [
				{ key: 'client_id', label: 'OAuth client ID', required: true },
				{ key: 'client_secret', label: 'OAuth client secret', type: 'secret' },
				{ key: 'folder_name', label: 'Folder name', placeholder: 'Nextcloud Tagged Backups' },
			],
		},
		gdrive_device: {
			label: 'Google Drive Device Sign-in',
			deviceFlow: true,
			help: 'Sign in by entering a short code at google.com/device; no redirect URI needed. Backups are placed in a folder the app creates (it only gets access to files it created itself).',
			ownClientHelp: 'In your Google Cloud project, enable the Google Drive API and create an OAuth client ID of type "TVs and Limited Input devices".',
			fields: [
				{ key: 'client_id', label: 'OAuth client ID', ownClient: true },
				{ key: 'client_secret', label: 'OAuth client secret', type: 'secret', ownClient: true },
				{ key: 'folder_name', label: 'Folder name', placeholder: 'Nextcloud Tagged Backups' },
			],
		},
		onedrive: {
			label: 'Microsoft OneDrive',
			oauth: true,
			help: 'In Microsoft Entra ID, register an application, add a "Web" platform with the redirect URI below, add the delegated Microsoft Graph permissions Files.ReadWrite and offline_access, and create a client secret.',
			fields: [
				{ key: 'tenant', label: 'Tenant', placeholder: 'common', hint: 'Use "common" for personal and work accounts, "consumers" for personal only, or your tenant ID.' },
				{ key: 'client_id', label: 'Application (client) ID', required: true },
				{ key: 'client_secret', label: 'Client secret', type: 'secret' },
				{ key: 'folder_path', label: 'Folder path', placeholder: 'Nextcloud Tagged Backups' },
			],
		},
	}

	const root = document.getElementById('tagged-backup')
	if (!root) {
		return
	}
	const redirectUri = root.dataset.redirectUri
	const hasBzip2 = root.dataset.hasBzip2 === '1'
	const hasGoogleDeviceClient = root.dataset.hasGoogleDeviceClient === '1'
	const tbody = document.getElementById('tagged-backup-jobs')
	const form = document.getElementById('tagged-backup-form')
	const previewBox = document.getElementById('tagged-backup-preview')
	const notice = document.getElementById('tagged-backup-notice')
	let tags = []
	let jobs = []
	let pollTimer = null

	const url = (path) => OC.generateUrl('/apps/tagged_backup' + path)

	async function api(method, path, body) {
		const response = await fetch(url(path), {
			method,
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken || document.head.dataset.requesttoken,
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		let data = null
		try {
			data = await response.json()
		} catch (e) {
			// empty body
		}
		if (!response.ok) {
			throw new Error((data && data.message) || ('Request failed (' + response.status + ')'))
		}
		return data
	}

	function el(tag, attrs, ...children) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(attrs || {})) {
			if (value === undefined || value === null || value === false) continue
			if (key === 'class') node.className = value
			else if (key.startsWith('on')) node.addEventListener(key.slice(2), value)
			else if (key in node && typeof value !== 'string') node[key] = value
			else node.setAttribute(key, value === true ? '' : value)
		}
		for (const child of children.flat()) {
			if (child === null || child === undefined || child === false) continue
			node.append(child instanceof Node ? child : document.createTextNode(String(child)))
		}
		return node
	}

	function showNotice(message, isError) {
		notice.textContent = message
		notice.className = 'tb-notice ' + (isError ? 'tb-error' : 'tb-ok')
		notice.hidden = false
		notice.scrollIntoView({ block: 'nearest' })
	}

	function formatBytes(bytes) {
		if (bytes === null || bytes === undefined) return ''
		const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
		let i = 0
		let value = bytes
		while (value >= 1024 && i < units.length - 1) {
			value /= 1024
			i++
		}
		return (i === 0 ? value : value.toFixed(1)) + ' ' + units[i]
	}

	function tagName(id) {
		const tag = tags.find((t) => String(t.id) === String(id))
		return tag ? tag.name : '#' + id
	}

	function renderJobs() {
		tbody.replaceChildren()
		if (jobs.length === 0) {
			tbody.append(el('tr', {}, el('td', { colspan: '6', class: 'tb-empty' }, 'No backup jobs yet.')))
			return
		}
		for (const job of jobs) {
			const status = job.running
				? el('span', { class: 'tb-status tb-running' }, 'running…')
				: job.lastStatus
					? el('span', { class: 'tb-status tb-' + job.lastStatus, title: job.lastMessage || '' }, job.lastStatus)
					: el('span', { class: 'tb-status' }, 'never')
			tbody.append(el('tr', { class: job.enabled ? '' : 'tb-disabled' },
				el('td', {}, job.name, job.enabled ? null : el('em', {}, ' (disabled)')),
				el('td', {}, job.tagIds.map(tagName).join(', ')),
				el('td', {}, DESTINATIONS[job.destinationType] ? DESTINATIONS[job.destinationType].label : job.destinationType,
					el('div', {},
						job.compression !== 'none' ? el('span', { class: 'tb-badge' }, job.compression) : null,
						job.ageRecipients ? el('span', { class: 'tb-badge', title: 'Encrypted with age' }, 'encrypted') : null)),
				el('td', {}, job.intervalHours > 0 ? 'every ' + job.intervalHours + ' h' : 'manual',
					job.retentionCount > 0 ? el('div', { class: 'tb-sub' }, 'keep ' + job.retentionCount) : null),
				el('td', {},
					job.lastRunAt ? new Date(job.lastRunAt * 1000).toLocaleString() + ' ' : '',
					status,
					job.lastMessage ? el('div', { class: 'tb-sub tb-message', title: job.lastMessage }, job.lastMessage) : null),
				el('td', {}, el('div', { class: 'tb-actions' },
					el('button', { type: 'button', onclick: () => openForm(job) }, 'Edit'),
					el('button', { type: 'button', onclick: () => runAction(job, 'test') }, 'Test connection'),
					el('button', { type: 'button', onclick: () => preview(job) }, 'Preview'),
					el('button', { type: 'button', onclick: () => runAction(job, 'run') }, 'Run now'),
					el('button', { type: 'button', class: 'tb-danger', onclick: () => removeJob(job) }, 'Delete'))),
			))
		}
	}

	async function load() {
		try {
			[tags, jobs] = await Promise.all([api('GET', '/api/tags'), api('GET', '/api/jobs')])
			renderJobs()
		} catch (e) {
			showNotice(e.message, true)
		}
		clearTimeout(pollTimer)
		if (jobs.some((j) => j.running)) {
			pollTimer = setTimeout(load, 10000)
		}
	}

	async function runAction(job, action) {
		try {
			const result = await api('POST', '/api/jobs/' + job.id + '/' + action)
			showNotice(job.name + ': ' + result.message, false)
		} catch (e) {
			showNotice(job.name + ': ' + e.message, true)
		}
	}

	async function removeJob(job) {
		if (!window.confirm('Delete backup job "' + job.name + '"? Existing backups at the destination are not touched.')) return
		try {
			await api('DELETE', '/api/jobs/' + job.id)
			form.hidden = true
			await load()
		} catch (e) {
			showNotice(e.message, true)
		}
	}

	async function preview(job) {
		previewBox.hidden = false
		previewBox.replaceChildren(el('p', {}, 'Collecting files for "' + job.name + '"…'))
		try {
			const result = await api('GET', '/api/jobs/' + job.id + '/preview')
			const total = result.files + result.directories
			previewBox.replaceChildren(
				el('h3', {}, 'Preview: ' + job.name),
				el('p', {}, result.files + ' files and ' + result.directories + ' folders, ' + formatBytes(result.bytes) + ' before compression.'),
				result.missing.length ? el('p', { class: 'tb-error' }, result.missing.length + ' tagged item(s) cannot be reached: ' + result.missing.map((m) => m.reason).join('; ')) : null,
				el('pre', {}, result.sample.join('\n') + (total > result.sample.length ? '\n… and ' + (total - result.sample.length) + ' more' : '')),
				el('button', { type: 'button', onclick: () => { previewBox.hidden = true } }, 'Close'),
			)
		} catch (e) {
			previewBox.replaceChildren(el('p', { class: 'tb-error' }, e.message))
		}
	}

	let deviceSession = 0

	async function deviceSignIn(job, box) {
		const session = ++deviceSession
		box.replaceChildren(el('p', {}, 'Requesting a sign-in code…'))
		let flow
		try {
			flow = await api('POST', '/api/jobs/' + job.id + '/device/start')
		} catch (e) {
			box.replaceChildren(el('p', { class: 'tb-error' }, e.message))
			return
		}
		const expiresAt = Date.now() + flow.expiresIn * 1000
		const status = el('p', { class: 'tb-hint' }, 'Waiting for you to approve…')
		box.replaceChildren(
			el('p', {}, 'Open ', el('a', { href: flow.verificationUrl, target: '_blank', rel: 'noopener noreferrer' }, flow.verificationUrl),
				' on any device, sign in with the Google account to back up to, and enter this code:'),
			el('p', { class: 'tb-user-code' }, flow.userCode),
			status,
			el('button', { type: 'button', onclick: () => { deviceSession++; box.replaceChildren() } }, 'Cancel'))

		let interval = flow.interval
		while (session === deviceSession && box.isConnected) {
			await new Promise((resolve) => setTimeout(resolve, interval * 1000))
			if (session !== deviceSession || !box.isConnected) return
			if (Date.now() > expiresAt) {
				box.replaceChildren(el('p', { class: 'tb-error' }, 'The code expired. Start the sign-in again.'))
				return
			}
			let result
			try {
				result = await api('POST', '/api/jobs/' + job.id + '/device/poll')
			} catch (e) {
				box.replaceChildren(el('p', { class: 'tb-error' }, e.message))
				return
			}
			if (result.status === 'slow_down') {
				interval += 5
			} else if (result.status === 'done') {
				showNotice('Connected Google account for "' + job.name + '". Use "Test connection" to verify.', false)
				await load()
				const fresh = jobs.find((j) => j.id === job.id)
				if (fresh) openForm(fresh)
				return
			}
			const minutes = Math.max(0, Math.round((expiresAt - Date.now()) / 60000))
			status.textContent = 'Waiting for you to approve… (code valid for about ' + minutes + ' more minute' + (minutes === 1 ? '' : 's') + ')'
		}
	}

	function field(label, input, hint) {
		return el('div', { class: 'tb-field' },
			el('label', {}, label, input),
			hint ? el('p', { class: 'tb-hint' }, hint) : null)
	}

	function openForm(job) {
		const isNew = !job
		job = job || {
			name: '', enabled: true, tagIds: [], scopeUser: '', compression: 'gzip', ageRecipients: '',
			destinationType: 's3', destination: {}, intervalHours: 24, retentionCount: 7,
		}
		form.replaceChildren()
		form.hidden = false
		previewBox.hidden = true

		const name = el('input', { type: 'text', value: job.name, required: true, maxlength: '128' })
		const enabled = el('input', { type: 'checkbox', checked: job.enabled })
		const tagBoxes = tags.map((t) => el('input', { type: 'checkbox', value: String(t.id), checked: job.tagIds.includes(String(t.id)) }))
		const tagList = el('div', { class: 'tb-tags' }, tags.map((t, i) => el('label', {}, tagBoxes[i], ' ' + t.name,
			t.userVisible ? null : el('span', { class: 'tb-sub' }, ' (invisible)'),
			t.userAssignable ? null : el('span', { class: 'tb-sub' }, ' (restricted)'))))
		const scopeUser = el('input', { type: 'text', value: job.scopeUser, placeholder: 'all users' })
		const compression = el('select', {},
			el('option', { value: 'gzip', selected: job.compression === 'gzip' }, 'gzip'),
			hasBzip2 || job.compression === 'bzip2' ? el('option', { value: 'bzip2', selected: job.compression === 'bzip2' }, 'bzip2 (smaller, slower)') : null,
			el('option', { value: 'none', selected: job.compression === 'none' }, 'none'))
		const recipients = el('textarea', { rows: '3', placeholder: 'age1… (one per line; leave empty for no encryption)' })
		recipients.value = job.ageRecipients
		const interval = el('input', { type: 'number', min: '0', value: String(job.intervalHours) })
		const retention = el('input', { type: 'number', min: '0', value: String(job.retentionCount) })
		const destType = el('select', {},
			Object.entries(DESTINATIONS).map(([key, d]) => el('option', { value: key, selected: job.destinationType === key }, d.label)))
		const destBox = el('div', { class: 'tb-destination' })
		const destInputs = {}

		function renderDestination() {
			destBox.replaceChildren()
			const type = destType.value
			const def = DESTINATIONS[type]
			const sameType = type === job.destinationType
			const values = sameType ? job.destination : {}
			for (const key of Object.keys(destInputs)) delete destInputs[key]
			if (def.help) {
				destBox.append(el('p', { class: 'tb-hint' }, def.help))
			}
			if (def.oauth) {
				destBox.append(field('Redirect URI', el('input', { type: 'text', readonly: true, value: redirectUri, onclick: (e) => e.target.select() })))
			}
			// With a default client configured, a job's own client is optional,
			// so tuck those fields away.
			let ownClientBox = null
			if (def.ownClientHelp) {
				if (hasGoogleDeviceClient) {
					ownClientBox = el('details', { class: 'tb-advanced', open: !!(sameType && values.client_id) },
						el('summary', {}, 'Use your own Google Cloud client (optional)'),
						el('p', { class: 'tb-hint' }, def.ownClientHelp + ' Leave empty to use this server\'s default client.'))
					destBox.append(ownClientBox)
				} else {
					destBox.append(el('p', { class: 'tb-hint' }, def.ownClientHelp))
				}
			}
			for (const f of def.fields) {
				const target = f.ownClient && ownClientBox ? ownClientBox : destBox
				let input
				if (f.type === 'bool') {
					input = el('input', { type: 'checkbox', checked: values[f.key] !== undefined ? !!values[f.key] : !!f.default })
					destBox.append(el('div', { class: 'tb-field tb-check' }, el('label', {}, input, ' ' + f.label)))
				} else if (f.type === 'secret') {
					const isSet = sameType && values[f.key + '_set']
					input = el('input', { type: 'password', autocomplete: 'new-password', placeholder: isSet ? '•••••••• (unchanged)' : '' })
					target.append(field(f.label, input, f.hint))
				} else {
					input = el('input', { type: 'text', value: values[f.key] || '', placeholder: f.placeholder || '' })
					target.append(field(f.label, input, f.hint))
				}
				destInputs[f.key] = input
				if (f.key === 'client_id') {
					input.addEventListener('input', () => updateWarning())
				}
			}
			if (def.deviceFlow) {
				const connected = sameType && values.refresh_token_set
				const deviceBox = el('div', { class: 'tb-device' })
				destBox.append(el('div', { class: 'tb-field' },
					el('strong', {}, connected ? 'Account connected. ' : 'No account connected. '),
					isNew || !sameType
						? el('span', { class: 'tb-hint' }, 'Save the job first, then sign in.')
						: el('button', { type: 'button', onclick: () => deviceSignIn(job, deviceBox) }, connected ? 'Sign in again' : 'Sign in with Google')),
				deviceBox)
			}
			if (def.oauth) {
				const connected = sameType && values.refresh_token_set
				destBox.append(el('div', { class: 'tb-field' },
					el('strong', {}, connected ? 'Account connected. ' : 'No account connected. '),
					isNew || !sameType
						? el('span', { class: 'tb-hint' }, 'Save the job first, then connect an account.')
						: el('a', { class: 'button', href: url('/oauth/start/' + job.id) }, connected ? 'Reconnect account' : 'Connect account')))
			}
		}
		// A shared (built-in or server default) Google client can be reused by
		// anyone, so warn when such backups are not encrypted.
		const sharedClientWarning = el('p', { class: 'tb-warning', hidden: true },
			'Backups for this destination are not encrypted. This destination signs in with a Google client that other installs may share. '
			+ 'If someone tricks the account owner into approving their sign-in code, they can read every backup this client created in that Drive. '
			+ 'Add an age recipient above so a stolen sign-in only exposes encrypted data.')
		function updateWarning() {
			const ownClient = destInputs.client_id && destInputs.client_id.value.trim() !== ''
			sharedClientWarning.hidden = !(destType.value === 'gdrive_device' && !ownClient && recipients.value.trim() === '')
		}
		recipients.addEventListener('input', updateWarning)
		destType.addEventListener('change', () => {
			renderDestination()
			updateWarning()
		})
		renderDestination()

		form.append(
			el('h3', {}, isNew ? 'New backup job' : 'Edit "' + job.name + '"'),
			field('Name', name),
			el('div', { class: 'tb-field tb-check' }, el('label', {}, enabled, ' Enabled (scheduled runs)')),
			el('div', { class: 'tb-field' }, el('span', { class: 'tb-label' }, 'Tags'), tagList,
				el('p', { class: 'tb-hint' }, tags.length ? 'Files and folders with any of these tags are backed up; folders include all their contents.' : 'No collaborative tags exist yet. Create some in the Files app first.')),
			field('Only files of user', scopeUser, 'Leave empty to back up tagged files of all users. Archive paths are files/<user>/<path>, or files/<path> when limited to one user.'),
			field('Compression', compression),
			field('Encrypt for age recipients', recipients, 'Public keys from age-keygen. Only the holders of the matching private keys can decrypt: age -d -i key.txt backup.tar.gz.age > backup.tar.gz'),
			sharedClientWarning,
			field('Run every (hours)', interval, '0 = only when started manually.'),
			field('Keep backups', retention, 'Older backups of this job are deleted at the destination after a successful upload. 0 = keep all.'),
			field('Destination', destType),
			destBox,
			el('div', { class: 'tb-buttons' },
				el('button', { type: 'submit', class: 'primary' }, 'Save'),
				el('button', { type: 'button', onclick: () => { form.hidden = true } }, 'Cancel')),
		)

		updateWarning()

		form.onsubmit = async (event) => {
			event.preventDefault()
			const destination = {}
			for (const [key, input] of Object.entries(destInputs)) {
				destination[key] = input.type === 'checkbox' ? input.checked : input.value
			}
			const payload = {
				name: name.value,
				enabled: enabled.checked,
				tagIds: tagBoxes.filter((b) => b.checked).map((b) => b.value),
				scopeUser: scopeUser.value,
				compression: compression.value,
				ageRecipients: recipients.value,
				intervalHours: parseInt(interval.value || '0', 10),
				retentionCount: parseInt(retention.value || '0', 10),
				destinationType: destType.value,
				destination,
			}
			try {
				const saved = isNew ? await api('POST', '/api/jobs', payload) : await api('PUT', '/api/jobs/' + job.id, payload)
				showNotice('Saved "' + saved.name + '".', false)
				await load()
				const def = DESTINATIONS[saved.destinationType]
				if ((def.oauth || def.deviceFlow) && !saved.destination.refresh_token_set) {
					openForm(saved) // show the "Connect account" / "Sign in" button
				} else {
					form.hidden = true
				}
			} catch (e) {
				showNotice(e.message, true)
			}
		}
		form.scrollIntoView({ block: 'start', behavior: 'smooth' })
		name.focus()
	}

	document.getElementById('tagged-backup-add').addEventListener('click', () => openForm(null))

	const params = new URLSearchParams(window.location.search)
	if (params.has('oauth_connected')) {
		showNotice('Connected account for "' + params.get('oauth_connected') + '". Use "Test connection" to verify.', false)
	} else if (params.has('oauth_error')) {
		showNotice('Connecting account failed: ' + params.get('oauth_error'), true)
	}

	load()
})()
