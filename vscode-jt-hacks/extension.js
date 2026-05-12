'use strict';

const vscode = require('vscode');
const { spawn } = require('child_process');

let lastMarkdownPath = null;

function rememberIfMarkdown(editor) {
	if (!editor || editor.document.isUntitled) return;
	const doc = editor.document;
	const isMd =
		doc.languageId === 'markdown' ||
		doc.fileName.toLowerCase().endsWith('.md');
	if (isMd && doc.uri.scheme === 'file') {
		lastMarkdownPath = doc.uri.fsPath;
	}
}

/**
 * Try to pull a markdown file path out of the active tab, even when a
 * Markdown preview webview is focused (which means activeTextEditor is null).
 * @returns {string | null}
 */
function getMarkdownPathFromActiveTab() {
	const tab = vscode.window.tabGroups?.activeTabGroup?.activeTab;
	if (!tab) return null;
	const input = tab.input;
	// TabInputText: regular editor
	if (input && input.uri && input.uri.scheme === 'file') {
		const fsPath = input.uri.fsPath;
		if (fsPath.toLowerCase().endsWith('.md')) return fsPath;
	}
	// TabInputWebview for markdown preview: viewType is 'mainThreadWebview-markdown.preview'.
	// The webview doesn't expose its source URI, so fall back to the tab label matching
	// a visible markdown editor, or the last remembered markdown path.
	if (input && typeof input.viewType === 'string' && input.viewType.includes('markdown.preview')) {
		for (const ed of vscode.window.visibleTextEditors) {
			const doc = ed.document;
			if (
				doc.uri.scheme === 'file' &&
				(doc.languageId === 'markdown' || doc.fileName.toLowerCase().endsWith('.md'))
			) {
				return doc.uri.fsPath;
			}
		}
		if (lastMarkdownPath) return lastMarkdownPath;
	}
	return null;
}

/**
 * @returns {string | null} Absolute path to the active file if it looks like Markdown.
 */
function getActiveMarkdownPath() {
	const editor = vscode.window.activeTextEditor;
	if (editor && !editor.document.isUntitled) {
		const doc = editor.document;
		const isMd =
			doc.languageId === 'markdown' ||
			doc.fileName.toLowerCase().endsWith('.md');
		if (isMd && doc.uri.scheme === 'file') return doc.uri.fsPath;
	}
	const fromTab = getMarkdownPathFromActiveTab();
	if (fromTab) return fromTab;
	vscode.window.showWarningMessage(
		'JT Hacks: Save the file first, then run this command with a Markdown file (editor or preview) focused.'
	);
	return null;
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
	rememberIfMarkdown(vscode.window.activeTextEditor);
	context.subscriptions.push(
		vscode.window.onDidChangeActiveTextEditor(rememberIfMarkdown),
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
