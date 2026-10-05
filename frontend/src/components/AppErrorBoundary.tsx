import { Component, type ErrorInfo, type ReactNode } from "react";

/**
 * Top-level error boundary.
 *
 * Catches render/lifecycle errors anywhere below it so an unexpected
 * exception in a route or component degrades to a recoverable message
 * instead of an empty white page.
 *
 * Deliberate behaviour:
 *  - Errors are re-thrown when `onReset` is not provided, so a boundary
 *    used mid-tree does not silently swallow a failure.
 *  - The stack trace is logged to the console but never rendered; users
 *    see a plain message plus a recovery control.
 */

interface AppErrorBoundaryProps {
  children: ReactNode;
  /** Optional label so nested boundaries can be told apart in the console. */
  label?: string;
  /** Called with the error when the boundary resets, e.g. to reload. */
  onReset?: () => void;
}

interface AppErrorBoundaryState {
  error: Error | null;
}

class AppErrorBoundary extends Component<
  AppErrorBoundaryProps,
  AppErrorBoundaryState
> {
  state: AppErrorBoundaryState = { error: null };

  static getDerivedStateFromError(error: Error): AppErrorBoundaryState {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Useful diagnostics for developers; deliberately not rendered to users.
    const where = this.props.label ? `[${this.props.label}] ` : "";
    console.error(
      `${where}Unhandled render error:`,
      error,
      info.componentStack ?? "",
    );
  }

  private handleReset = (): void => {
    const { onReset } = this.props;

    // No reset handler supplied: surface the failure rather than hiding it.
    if (!onReset) {
      throw this.state.error;
    }

    this.setState({ error: null });
    onReset();
  };

  render(): ReactNode {
    const { error } = this.state;

    if (!error) {
      return this.props.children;
    }

    return (
      <div
        role="alert"
        aria-live="assertive"
        data-testid="app-error-boundary"
        className="min-h-screen bg-slate-950 flex flex-col items-center justify-center px-6 text-center"
      >
        <div className="max-w-md space-y-5">
          <div className="flex items-center justify-center">
            <div className="w-12 h-12 rounded-full border-2 border-amber-500/20 flex items-center justify-center">
              <span className="text-xl text-amber-400" aria-hidden="true">
                !
              </span>
            </div>
          </div>

          <div className="space-y-2">
            <h1 className="text-xl font-black text-white">
              Something went wrong on this page
            </h1>
            <p className="text-sm text-slate-400">
              The page ran into an unexpected problem. You can try again, or go
              back to the homepage and continue from there.
            </p>
          </div>

          <div className="flex flex-col sm:flex-row gap-3 justify-center pt-1">
            <button
              type="button"
              onClick={this.handleReset}
              className="px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-500 transition-colors text-sm font-bold text-white"
            >
              Try again
            </button>
            <a
              href="/"
              className="px-5 py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 transition-colors text-sm font-bold text-slate-200"
            >
              Back to homepage
            </a>
          </div>
        </div>
      </div>
    );
  }
}

export default AppErrorBoundary;
