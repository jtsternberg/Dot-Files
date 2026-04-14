'use strict';

const vscode = require('vscode');
const { spawn } = require('child_process');

/**
 * @returns {string | null} Absolute path to the active file if it looks like Markdown.
 */
function getActiveMarkdownPath() {
	const editor = vscode.window.activeTextEditor;
	if (!editor || editor.document.isUntitled) {
		vscode.window.showWarningMessage(
			'JT Hacks: Save the file first, then run this command with the Markdown editor focused.'
		);
		return null;
	}
	const doc = editor.document;
	const isMd =
		doc.languageId === 'markdown' ||
		doc.fileName.toLowerCase().endsWith('.md');
	if (!isMd) {
		vscode.window.showWarningMessage(
			'JT Hacks: Active file is not Markdown (.md).'
		);
		return null;
	}
	return doc.uri.fsPath;
}

/**
 * @param {string} appName macOS application name for `open -a` (e.g. MacDown, Clearly)
 * @param {string} filePath
 */
function openInMacApp(appName, filePath) {
	if (process.platform !== 'darwin') {
		vscode.window.showErrorMessage(
			'JT Hacks: Opening in external apps is only wired up for macOS.'
		);
		return;
	}
	const child = spawn('open', ['-a', appName, filePath], {
		detached: true,
		stdio: 'ignore'
	});
	child.on('error', (err) => {
		vscode.window.showErrorMessage(
			`JT Hacks: could not run open: ${err.message}`
		);
	});
	child.unref();
}

/**
 * @param {vscode.ExtensionContext} context
 */
function activate(context) {
	console.log('[jt-hacks] extension activated');
	context.subscriptions.push(
		vscode.commands.registerCommand('jt-hacks.openMarkdownInMacDown', () => {
			const path = getActiveMarkdownPath();
			if (path) {
				openInMacApp('MacDown', path);
			}
		}),
		vscode.commands.registerCommand('jt-hacks.openMarkdownInClearly', () => {
			const path = getActiveMarkdownPath();
			if (path) {
				openInMacApp('Clearly', path);
			}
		})
	);
}

function deactivate() {}

module.exports = { activate, deactivate };
