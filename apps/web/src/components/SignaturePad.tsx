"use client";

import { useEffect, useRef, useState } from "react";

/**
 * Zone de signature manuscrite pour la preuve de livraison.
 *
 * L'exploitant fait signer sur son écran chez le destinataire : le tracé est
 * capturé au doigt comme à la souris (événements pointeur), puis exporté en
 * PNG (data URI) — le format qu'attend `proof_of_deliveries.signature_data`.
 *
 * Le canvas est dimensionné en pixels physiques pour rester net sur les écrans
 * à forte densité, où un tracé rendu en pixels CSS apparaîtrait flou.
 */
export function SignaturePad({
  value,
  onChange,
  height = 160,
}: {
  value: string | null;
  onChange: (dataUri: string | null) => void;
  height?: number;
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);
  // Le tracé est suivi par une ref et non par l'état : la fin du geste doit
  // savoir immédiatement qu'on a dessiné, sans attendre le rendu suivant.
  const drawn = useRef(false);
  const [hasStrokes, setHasStrokes] = useState(false);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (canvas === null) return;

    const ratio = window.devicePixelRatio || 1;
    const width = canvas.parentElement?.clientWidth ?? 480;
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    canvas.style.height = `${height}px`;

    const context = canvas.getContext("2d");
    if (context === null) return;
    context.scale(ratio, ratio);
    context.lineWidth = 2;
    context.lineCap = "round";
    context.lineJoin = "round";
    // Le trait suit la couleur du texte : lisible en thème clair comme sombre.
    context.strokeStyle = getComputedStyle(canvas).color;
  }, [height]);

  // Une signature effacée en dehors du composant (réouverture du formulaire)
  // doit vider le canvas, sans quoi le tracé précédent resterait affiché.
  useEffect(() => {
    if (value !== null) return;
    const canvas = canvasRef.current;
    canvas?.getContext("2d")?.clearRect(0, 0, canvas.width, canvas.height);
    drawn.current = false;
    setHasStrokes(false);
  }, [value]);

  const positionOf = (event: React.PointerEvent<HTMLCanvasElement>) => {
    const bounds = event.currentTarget.getBoundingClientRect();

    return { x: event.clientX - bounds.left, y: event.clientY - bounds.top };
  };

  const start = (event: React.PointerEvent<HTMLCanvasElement>) => {
    const context = canvasRef.current?.getContext("2d");
    if (!context) return;
    event.currentTarget.setPointerCapture(event.pointerId);
    drawing.current = true;
    const { x, y } = positionOf(event);
    context.beginPath();
    context.moveTo(x, y);
  };

  const move = (event: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawing.current) return;
    const context = canvasRef.current?.getContext("2d");
    if (!context) return;
    const { x, y } = positionOf(event);
    context.lineTo(x, y);
    context.stroke();
    drawn.current = true;
    setHasStrokes(true);
  };

  const end = () => {
    if (!drawing.current) return;
    drawing.current = false;
    const canvas = canvasRef.current;
    if (canvas !== null && drawn.current) onChange(canvas.toDataURL("image/png"));
  };

  const clear = () => {
    const canvas = canvasRef.current;
    canvas?.getContext("2d")?.clearRect(0, 0, canvas.width, canvas.height);
    drawn.current = false;
    setHasStrokes(false);
    onChange(null);
  };

  return (
    <div className="flex flex-col gap-1.5">
      <canvas
        ref={canvasRef}
        onPointerDown={start}
        onPointerMove={move}
        onPointerUp={end}
        onPointerLeave={end}
        // Sans cela, signer au doigt ferait défiler la page sous le tracé.
        className="w-full touch-none rounded-xl border border-dashed border-line-strong bg-paper"
      />
      <div className="flex items-center gap-3">
        <button type="button" onClick={clear} className="text-xs font-semibold text-sea hover:underline">
          Effacer
        </button>
        <span className="text-[11px] text-ink-3">
          {hasStrokes ? "Signature capturée" : "Faites signer le destinataire dans le cadre"}
        </span>
      </div>
    </div>
  );
}
