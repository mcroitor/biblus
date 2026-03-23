function upload() {
	const fileInput = document.getElementById('page-image-btn');
	const resultBlock = document.getElementById('result-block');
	resultBlock.textContent = 'Uploading...';

	if (!fileInput.files || fileInput.files.length === 0) {
		resultBlock.textContent = 'No file selected.';
		return;
	}

	const file = fileInput.files[0];
	const formData = new FormData();
	formData.append('file', file);

	fetch('/api.php/upload', {
		method: 'POST',
		body: formData
	})
	.then(response => response.json())
	.then(data => {
		if (data.success) {
			resultBlock.textContent = 'Upload successful! File: ' + data.path;
		} else {
			resultBlock.textContent = 'Error: ' + (data.error || 'Unknown error');
		}
	})
	.catch(error => {
		resultBlock.textContent = 'Error: ' + error;
	});
}
