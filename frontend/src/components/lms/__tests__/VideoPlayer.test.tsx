import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, cleanup, act } from "@testing-library/react";

vi.mock("../SecureVideoPlayer", () => ({ default: () => <div>SECURE</div> }));

import VideoPlayer from "../VideoPlayer";

const YT_ORIGIN = "https://www.youtube-nocookie.com";

function youtubeLesson() {
  return {
    id: 11,
    course_id: 5,
    section_id: 2,
    title: "YouTube Lesson",
    type: "video",
    duration: "25 min",
    sort_order: 1,
    metadata: { video_url: "https://www.youtube.com/watch?v=2ePf9rue1Ao" },
  } as any;
}

function playerMessage(payload: unknown, origin = YT_ORIGIN) {
  const evt = new MessageEvent("message", {
    data: typeof payload === "string" ? payload : JSON.stringify(payload),
  });
  Object.defineProperty(evt, "origin", { value: origin });
  act(() => {
    window.dispatchEvent(evt);
  });
}

describe("VideoPlayer embeds", () => {
  afterEach(() => cleanup());

  it("renders a metered nocookie YouTube iframe with the API channel open", () => {
    render(<VideoPlayer lesson={youtubeLesson()} />);
    const frame = screen.getByTitle("YouTube Lesson") as HTMLIFrameElement;
    expect(frame.tagName).toBe("IFRAME");
    expect(frame.src).toContain("youtube-nocookie.com/embed/2ePf9rue1Ao");
    expect(frame.src).toContain("enablejsapi=1");
  });

  it("forwards YouTube playback time into onProgress and ignores anything else", () => {
    const onProgress = vi.fn();
    render(<VideoPlayer lesson={youtubeLesson()} onProgress={onProgress} />);

    playerMessage({ event: "onReady", id: 1 });
    playerMessage(
      { event: "infoDelivery", info: { currentTime: 10, duration: 100 } },
    );
    expect(onProgress).toHaveBeenCalledWith(10, 100);

    // Wrong origin, garbage payloads, and non-finite numbers are ignored.
    playerMessage({ event: "infoDelivery", info: { currentTime: 50, duration: 100 } }, "https://evil.example.com");
    playerMessage("not-json{{{");
    playerMessage({ event: "infoDelivery", info: { currentTime: "soon", duration: 100 } });
    playerMessage({ event: "something-else" });
    expect(onProgress).toHaveBeenCalledTimes(1);
  });

  it("reports full watch when YouTube playback ends", () => {
    const onProgress = vi.fn();
    render(<VideoPlayer lesson={youtubeLesson()} onProgress={onProgress} />);

    playerMessage({ event: "onReady", id: 1 });
    playerMessage({ event: "infoDelivery", info: { currentTime: 50, duration: 100 } });
    playerMessage({ event: "onStateChange", info: 0 });

    expect(onProgress).toHaveBeenLastCalledWith(100, 100);
  });

  it("renders Vimeo embeds without metering and keeps HLS on the secure player", async () => {
    const onProgress = vi.fn();
    const { rerender } = render(
      <VideoPlayer
        lesson={{ ...youtubeLesson(), metadata: { video_url: "https://vimeo.com/123456789" } }}
        onProgress={onProgress}
      />
    );
    const frame = screen.getByTitle("YouTube Lesson") as HTMLIFrameElement;
    expect(frame.src).toContain("player.vimeo.com/video/123456789");

    // Vimeo posts nothing we consume: no fabricated progress ever fires.
    playerMessage({ event: "infoDelivery", info: { currentTime: 90, duration: 100 } }, "https://player.vimeo.com");
    expect(onProgress).not.toHaveBeenCalled();

    rerender(
      <VideoPlayer
        lesson={{ ...youtubeLesson(), metadata: {} }}
        onProgress={onProgress}
      />
    );
    // B19: the secure player is lazy-loaded (it carries hls.js), so it resolves
    // asynchronously behind a Suspense fallback rather than synchronously.
    expect(await screen.findByText("SECURE")).toBeTruthy();
  });

  it("still resolves to the secure player for the HLS branch after the dynamic import", async () => {
    // B19 moved SecureVideoPlayer behind a dynamic import so hls.js leaves the
    // StudentLessons route chunk. The protected-video branch must still resolve
    // to that component — the routing behaviour must be unchanged.
    render(<VideoPlayer lesson={{ ...youtubeLesson(), metadata: {} }} />);

    expect(await screen.findByText("SECURE")).toBeTruthy();
  });
});
