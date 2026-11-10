import { describe, expect, it } from "vitest";
import { parseChecklistLines } from "./setup-screen";

describe("parseChecklistLines", () => {
  it("makes one item per line, marks ?-prefixed lines optional and skips blanks", () => {
    expect(parseChecklistLines("Names checked\n\n? Photo taken\r\nSizes checked\n?")).toEqual([
      { label: "Names checked", is_required: true },
      { label: "Photo taken", is_required: false },
      { label: "Sizes checked", is_required: true },
    ]);
  });
});
