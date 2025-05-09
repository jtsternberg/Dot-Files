(function() {
	function getElementSummarySelector(el) {
		if (!(el instanceof Element)) return '';

		if (el.id) {
			return `#${el.id}`;
		}

		if (el.className) {
			return `.${el.className.trim().split(/\s+/).join('.')}`;
		}

		const tag = el.tagName.toLowerCase();

		// Fallback to using attributes if no classes
		const attrPairs = Array.from(el.attributes)
			.map(attr => `[${attr.name}="${attr.value}"]`)
			.join('');

		return `${tag}${attrPairs}`;
	}

	// Grab the first form on the page and create a selector for it
	const exampleFormSelector = getElementSummarySelector(document.querySelector('form')) || '#wpforms-form-33545';
	const formSelector = prompt('Enter the form selector:', exampleFormSelector);
	if (!formSelector) {
		console.error('No form selector provided');
		return;
	}

	const formToJson = (form) => {
		const formData = new FormData(form);
		const json = {};
		formData.forEach((value, key) => {
			json[key] = value;
		});
		return json;
	};

	const saveFormData = (formElement) => {
		const json = formToJson(formElement);
		localStorage.setItem(formSelector, JSON.stringify(json));
		console.log('Form data saved to localStorage:', {
			formSelector,
			json,
		});
	};

	const formElement = document.querySelector(formSelector);
	if (!formElement) {
		console.error('Form not found:', formSelector);
		return;
	}

	// Listen for changes to the form
	formElement.addEventListener('change', (event) => {
		saveFormData(formElement);
	});
	formElement.addEventListener('submit', (event) => {
		event.preventDefault();
		saveFormData(formElement);
	});

	saveFormData(formElement);

})();