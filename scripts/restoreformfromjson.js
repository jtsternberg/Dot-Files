(function() {

	const jsonToForm = (form, jsonInput) => {
		const data = typeof jsonInput === 'string' ? JSON.parse(jsonInput) : jsonInput;

		Object.entries(data).forEach(([key, val]) => {
			const field = form.elements.namedItem(key);
			if (!field) return;

			if (field.type === 'checkbox' || field.type === 'radio') {
				if (Array.isArray(data[key])) {
				Array.from(form.elements[key]).forEach(el => {
					el.checked = data[key].includes(el.value);
				});
				console.log('checkbox or radio updated', key, {val: data[key]});
				} else {
				field.checked = field.value === val.toString();
				console.log('checkbox or radio updated', key, {val: data[key]});
				}
			} else {
				field.value = val;
				console.log('field updated', key, {val});
			}
		});
	};

	let suggestedStorageKey = '';

	// Loop through all localStorage items and find the first that looks like it has form json data stringified
	for (const key in localStorage) {
		// Get value
		const value = localStorage.getItem(key);

		// Does value exist?
		if (!value) continue;

		// Is value a string?
		if (typeof value !== 'string') continue;

		// Is value a valid JSON string?
		let json = null;
		try {
			json = JSON.parse(value);
		} catch (e) {
			continue;
		}

		// Is json a valid form data object?
		if (!json || typeof json !== 'object') continue;

		if (!Object.keys(json).length) continue;

		// Set suggestedStorageKey
		suggestedStorageKey = key;

		// Break out of loop
		break;
	}

	if (!suggestedStorageKey) {
		alert('It doesn\'t look like we found any form data in localStorage!');
		return;
	}

	const storageKey = prompt('Enter the storage key:', suggestedStorageKey);
	if (!storageKey) {
		console.error('No storage key provided');
		return;
	}

	// Example usage:
	const formElement = document.querySelector(storageKey);
	if (!formElement) {
		console.error('Form not found:', storageKey);
		return;
	}

	// Let's say you already have this JSON string:
	const jsonInput = localStorage.getItem(storageKey);

	jsonToForm(formElement, jsonInput);
})();