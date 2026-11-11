import { afterEach, describe, expect, it, vi } from "vitest";
import { gatePassBody, gatePassNumberError, printServerDocument, printStatusTone, sampleBody, sampleImageError, sampleTone } from "./api";

const file = (name: string, type: string, size = 10) => new File([new Uint8Array(size)], name, { type });

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("sampleImageError", () => {
  it("accepts JPG, PNG and WebP up to 10 MB", () => {
    expect(sampleImageError(file("s.png", "image/png"))).toBeNull();
    expect(sampleImageError(file("s.jpg", "image/jpeg"))).toBeNull();
    expect(sampleImageError(file("s.webp", "image/webp"))).toBeNull();
  });

  it("rejects missing, other types, empty and oversized files", () => {
    expect(sampleImageError(null)).toBe("Choose the sample image.");
    expect(sampleImageError(file("s.svg", "image/svg+xml"))).toContain("JPG, PNG or WebP");
    expect(sampleImageError(file("s.pdf", "application/pdf"))).toContain("JPG, PNG or WebP");
    expect(sampleImageError(file("s.png", "image/png", 0))).toContain("10 MB");
    expect(sampleImageError(file("s.png", "image/png", 10 * 1024 * 1024 + 1))).toContain("10 MB");
  });
});

describe("gatePassNumberError", () => {
  it("mirrors the server rule", () => {
    expect(gatePassNumberError("GP-2026/0042")).toBeNull();
    expect(gatePassNumberError("")).toBe("Enter the gate pass number.");
    expect(gatePassNumberError("<b>1</b>")).not.toBeNull();
    expect(gatePassNumberError("x".repeat(51))).not.toBeNull();
  });
});

describe("multipart bodies", () => {
  it("builds the sample upload with version, image, documents and trimmed notes", () => {
    const body = sampleBody(4, { image: file("s.png", "image/png"), documents: [file("a.pdf", "application/pdf"), file("b.pdf", "application/pdf")], notes: "  First  " });
    expect(body.get("version")).toBe("4");
    expect((body.get("image") as File).name).toBe("s.png");
    expect((body.get("documents[1]") as File).name).toBe("b.pdf");
    expect(body.get("notes")).toBe("First");
  });

  it("omits an empty gate pass document and notes", () => {
    const body = gatePassBody({ gate_pass_no: " GP-1 ", gate_pass_date: "2026-11-09", document: null, notes: " " });
    expect(body.get("gate_pass_no")).toBe("GP-1");
    expect(body.has("document")).toBe(false);
    expect(body.has("notes")).toBe(false);
  });
});

describe("tones", () => {
  it("maps statuses to badge tones", () => {
    expect(printStatusTone("printing_completed")).toBe("success");
    expect(printStatusTone("sample_rejected")).toBe("danger");
    expect(printStatusTone("sample_pending_approval")).toBe("warning");
    expect(printStatusTone("physical_printing")).toBe("brand");
    expect(sampleTone("rejected")).toBe("danger");
  });
});

describe("printServerDocument", () => {
  it("surfaces the server refusal and never opens a print frame", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: "Printing is locked.", code: "document_printing_locked" }), { status: 409 })));
    await expect(printServerDocument(7, "job-card")).rejects.toMatchObject({ status: 409, code: "document_printing_locked" });
    expect(document.querySelector("iframe")).toBeNull();
  });

  it("prints the server-generated HTML from a sandboxed frame without scripts", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response("<html><body>Job Card</body></html>", { status: 200, headers: { "Content-Type": "text/html" } }));
    vi.stubGlobal("fetch", fetchMock);
    URL.createObjectURL = vi.fn(() => "blob:print");
    await printServerDocument(7, "name-list");
    expect(String(fetchMock.mock.calls[0][0])).toContain("/api/v1/print-jobs/7/documents/name-list/print");
    const frame = document.querySelector("iframe");
    expect(frame?.getAttribute("sandbox")).toBe("allow-same-origin allow-modals");
    expect(frame?.getAttribute("sandbox")).not.toContain("allow-scripts");
    frame?.remove();
  });
});
