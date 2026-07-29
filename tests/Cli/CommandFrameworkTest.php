<?php
namespace JT\Tests\Cli;

use JT\CLI\Attributes\Argument;
use JT\CLI\Attributes\Command;
use JT\CLI\Attributes\Option;
use JT\CLI\Attributes\Program;
use JT\CLI\Command\Dispatcher;
use JT\CLI\Command\Registry;
use JT\CLI\Command\ZshCompletion;
use JT\Tests\TestCase;

#[Program(
	name: 'demo',
	description: 'Exercise the attribute-driven command framework.',
)]
final class DemoCommand {

	public array $calls = [];

	#[Command(
		description: 'Run the default action.',
		default: true,
	)]
	public function run(
		#[Argument(
			description: 'Target to run.',
			completionCommand: 'demo-targets',
		)]
		string $target,
		#[Option(
			description: 'Fallback command.',
			valueName: 'command',
		)]
		?string $fallback = null
	): int {
		$this->calls[] = [ 'run', $target, $fallback ];

		return 3;
	}

	#[Command(
		name: 'greet',
		description: 'Greet a person.',
	)]
	public function greet(
		#[Argument(description: 'Person to greet.')]
		string $name,
		#[Option(
			aliases: [ 'l' ],
			description: 'Use a loud greeting.',
		)]
		bool $loud = false
	): int {
		$this->calls[] = [ 'greet', $name, $loud ];

		return 7;
	}

	#[Command(
		name: 'internal',
		description: 'Hidden compatibility helper.',
		hidden: true,
	)]
	public function internal(): int {
		$this->calls[] = [ 'internal' ];

		return 0;
	}
}

final class CommandFrameworkTest extends TestCase {

	public function testRegistryDerivesACommandDefinitionFromAttributes(): void {
		$definition = Registry::fromHandler( new DemoCommand() );
		$greet      = $definition->command( 'greet' );

		$this->assertSame( 'demo', $definition->name );
		$this->assertSame( 'run', $definition->defaultCommand()->method->getName() );
		$this->assertSame( 'greet', $greet->name );
		$this->assertSame( 'name', $greet->parameters[0]->name );
		$this->assertSame( 'loud', $greet->parameters[1]->name );
		$this->assertSame( [ 'l' ], $greet->parameters[1]->aliases );
		$this->assertTrue( $definition->command( 'internal' )->hidden );
	}

	public function testDispatcherBindsArgumentsAndOptionsThenInvokesTheMethod(): void {
		$handler = new DemoCommand();
		$this->cli->setArgs( [ 'demo', 'greet', 'JT', '-l' ] );

		$code = ( new Dispatcher( $this->cli, $handler ) )->run();

		$this->assertSame( 7, $code );
		$this->assertSame( [ [ 'greet', 'JT', true ] ], $handler->calls );
	}

	public function testDispatcherUsesTheDefaultMethodWhenTheFirstArgumentIsNotACommand(): void {
		$handler = new DemoCommand();
		$this->cli->setArgs( [ 'demo', 'dotfiles', '--fallback=git status' ] );

		$code = ( new Dispatcher( $this->cli, $handler ) )->run();

		$this->assertSame( 3, $code );
		$this->assertSame( [ [ 'run', 'dotfiles', 'git status' ] ], $handler->calls );
	}

	public function testDispatcherReportsAMissingRequiredArgumentWithoutCallingTheHandler(): void {
		$handler = new DemoCommand();
		$this->cli->setArgs( [ 'demo', 'greet' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, $code );
		$this->assertSame( [], $handler->calls );
		$this->assertStringContainsString( 'Missing required argument: <name>', $output );
	}

	public function testHelpRendersFromTheReflectedDefinition(): void {
		$definition = Registry::fromHandler( new DemoCommand() );
		$output     = $this->cli->getHelp()->renderProgram( $definition );

		$this->assertStringContainsString( 'Exercise the attribute-driven command framework.', $output );
		$this->assertStringContainsString( 'usage: demo <target> [--fallback=<command>]', $output );
		$this->assertStringContainsString( 'demo greet <name> [--loud|-l]', $output );
		$this->assertStringNotContainsString( 'internal', $output );
	}

	public function testDispatcherExposesHelpAndZshCompletionAsBuiltInCommands(): void {
		$handler = new DemoCommand();
		$this->cli->setArgs( [ 'demo', 'help', 'greet' ] );

		ob_start();
		$helpCode = ( new Dispatcher( $this->cli, $handler ) )->run();
		$help     = (string) ob_get_clean();

		$this->cli->setArgs( [ 'demo', 'completion', 'zsh' ] );
		ob_start();
		$completionCode = ( new Dispatcher( $this->cli, $handler ) )->run();
		$completion     = (string) ob_get_clean();

		$this->assertSame( 0, $helpCode );
		$this->assertStringContainsString( 'usage: demo greet <name> [--loud|-l]', $help );
		$this->assertSame( 0, $completionCode );
		$this->assertStringContainsString( '#compdef demo', $completion );
		$this->assertStringContainsString( 'compdef _demo demo', $completion );
	}

	public function testCompletionCommandHasGeneratedHelp(): void {
		$handler = new DemoCommand();
		$this->cli->setArgs( [ 'demo', 'completion', 'zsh', '--help' ] );

		ob_start();
		$code   = ( new Dispatcher( $this->cli, $handler ) )->run();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'Generate the Zsh completion script.', $output );
		$this->assertStringContainsString( 'usage: demo completion zsh', $output );
	}

	public function testZshCompletionIncludesContextualCommandsOptionsAndProviders(): void {
		$output = ( new ZshCompletion() )->render( Registry::fromHandler( new DemoCommand() ) );

		$this->assertStringStartsWith( '# BEGIN GENERATED COMPLETION: demo', $output );
		$this->assertStringEndsWith( "# END GENERATED COMPLETION: demo\n", $output );
		$this->assertStringContainsString( "'greet:Greet a person.'", $output );
		$this->assertStringContainsString( "'--loud[Use a loud greeting.]'", $output );
		$this->assertStringContainsString( 'demo-targets', $output );
		$this->assertStringNotContainsString( 'internal:', $output );
	}
}
