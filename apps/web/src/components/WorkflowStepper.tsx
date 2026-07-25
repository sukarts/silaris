interface Step {
  key: string;
  label: string;
  position: number;
}

export function WorkflowStepper({ steps, current }: { steps: Step[]; current: string }) {
  const currentPosition = steps.find((step) => step.key === current)?.position ?? 0;

  return (
    <div className="flex overflow-x-auto py-1" aria-label="Progression du workflow">
      {steps.map((step) => {
        const state = step.position < currentPosition ? "done" : step.key === current ? "current" : "todo";
        return (
          <div key={step.key} className="relative min-w-24 flex-1 pt-6 text-center text-[11px]">
            {step.position > 1 && (
              <div
                className={`absolute left-[calc(-50%+8px)] right-[calc(50%+8px)] top-[7px] h-0.5 ${
                  state === "todo" ? "bg-line-strong" : "bg-ok"
                }`}
              />
            )}
            <div
              className={`absolute left-1/2 top-0 z-10 size-3.5 -translate-x-1/2 rounded-full border-2 ${
                state === "done"
                  ? "border-ok bg-ok"
                  : state === "current"
                    ? "border-accent bg-accent shadow-[0_0_0_4px_var(--accent-soft)]"
                    : "border-line-strong bg-surface"
              }`}
            />
            <span className={state === "current" ? "font-bold text-accent-ink" : state === "done" ? "text-ink-2" : "text-ink-3"}>
              {step.label}
            </span>
          </div>
        );
      })}
    </div>
  );
}
