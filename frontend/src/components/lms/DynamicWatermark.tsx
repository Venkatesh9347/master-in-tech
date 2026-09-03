import { useEffect, useRef } from 'react';

interface DynamicWatermarkProps {
  mobileNumber: string;
  onTamperDetected?: () => void;
}

export default function DynamicWatermark({
  mobileNumber,
  onTamperDetected,
}: DynamicWatermarkProps) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const animFrameId = useRef<number | null>(null);

  // Position and velocity state for continuous wandering animation
  const state = useRef({
    x: 40,
    y: 40,
    vx: 0.7,
    vy: 0.5,
    opacity: 0.32,
    lastHop: 0,
  });

  useEffect(() => {
    state.current.lastHop = Date.now();
    const canvas = canvasRef.current;
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    let isRunning = true;

    const resizeCanvas = () => {
      if (!canvas || !containerRef.current) return;
      const rect = containerRef.current.getBoundingClientRect();
      const dpr = window.devicePixelRatio || 1;
      canvas.width = rect.width * dpr;
      canvas.height = rect.height * dpr;
      ctx.scale(dpr, dpr);
    };

    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    const render = () => {
      if (!isRunning || !canvas || !containerRef.current) return;
      const rect = containerRef.current.getBoundingClientRect();
      const width = rect.width;
      const height = rect.height;

      if (width === 0 || height === 0) {
        animFrameId.current = requestAnimationFrame(render);
        return;
      }

      ctx.clearRect(0, 0, width, height);

      // Periodic random position shift every 9 seconds to prevent static crop filters
      if (Date.now() - state.current.lastHop > 9000) {
        state.current.lastHop = Date.now();
        state.current.x = Math.max(30, Math.random() * (width - 220));
        state.current.y = Math.max(30, Math.random() * (height - 60));
        state.current.vx = (Math.random() > 0.5 ? 1 : -1) * (0.5 + Math.random() * 0.4);
        state.current.vy = (Math.random() > 0.5 ? 1 : -1) * (0.4 + Math.random() * 0.4);
      }

      // Continuous wandering movement
      state.current.x += state.current.vx;
      state.current.y += state.current.vy;

      // Bounce off boundaries
      const textWidth = 200;
      const textHeight = 30;

      if (state.current.x <= 15) {
        state.current.x = 15;
        state.current.vx = Math.abs(state.current.vx);
      } else if (state.current.x + textWidth >= width - 15) {
        state.current.x = width - textWidth - 15;
        state.current.vx = -Math.abs(state.current.vx);
      }

      if (state.current.y <= 25) {
        state.current.y = 25;
        state.current.vy = Math.abs(state.current.vy);
      } else if (state.current.y + textHeight >= height - 20) {
        state.current.y = height - textHeight - 20;
        state.current.vy = -Math.abs(state.current.vy);
      }

      // Render full registered mobile number with high-contrast text shadow
      ctx.save();
      ctx.font = 'bold 13px ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
      ctx.shadowColor = 'rgba(0, 0, 0, 0.85)';
      ctx.shadowBlur = 4;
      ctx.shadowOffsetX = 1;
      ctx.shadowOffsetY = 1;
      ctx.fillStyle = `rgba(255, 255, 255, ${state.current.opacity})`;

      ctx.fillText(mobileNumber, state.current.x, state.current.y);

      // Subtle session verification tag underneath
      ctx.font = '9px system-ui, -apple-system, sans-serif';
      ctx.fillStyle = `rgba(255, 255, 255, ${state.current.opacity * 0.8})`;
      ctx.fillText('MasterInTech Verified', state.current.x, state.current.y + 14);

      ctx.restore();

      animFrameId.current = requestAnimationFrame(render);
    };

    animFrameId.current = requestAnimationFrame(render);

    return () => {
      isRunning = false;
      if (animFrameId.current) cancelAnimationFrame(animFrameId.current);
      window.removeEventListener('resize', resizeCanvas);
    };
  }, [mobileNumber]);

  // Anti-Tamper DOM MutationObserver Guard
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        if (mutation.type === 'attributes') {
          const target = mutation.target as HTMLElement;
          const style = window.getComputedStyle(target);
          if (
            style.display === 'none' ||
            style.visibility === 'hidden' ||
            parseFloat(style.opacity || '1') < 0.05
          ) {
            onTamperDetected?.();
          }
        }
      }
    });

    observer.observe(container, {
      attributes: true,
      attributeFilter: ['style', 'class', 'hidden'],
      childList: true,
      subtree: true,
    });

    return () => observer.disconnect();
  }, [onTamperDetected]);

  return (
    <div
      ref={containerRef}
      id="mit-dynamic-watermark-overlay"
      className="absolute inset-0 pointer-events-none z-30 select-none overflow-hidden"
      aria-hidden="true"
    >
      <canvas
        ref={canvasRef}
        className="w-full h-full block pointer-events-none"
      />
    </div>
  );
}
