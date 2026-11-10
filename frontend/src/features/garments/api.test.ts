import { describe, expect, it } from "vitest";
import { type GarmentField, parseBulkRows } from "./api";

const sizes = [
  { id: 1, code: "S", label: "Small" },
  { id: 2, code: "M", label: "Medium" },
];
const fields: GarmentField[] = [
  { key: "player_name", label: "Player Name", input_type: "text", is_required: false, affects_price: false, warn_on_duplicate: false, max_length: 30, options: [] },
  { key: "number", label: "Player Number", input_type: "number", is_required: false, affects_price: false, warn_on_duplicate: true, max_length: 3, options: [] },
  { key: "sleeve_type", label: "Sleeve Type", input_type: "select", is_required: true, affects_price: true, warn_on_duplicate: false, max_length: null, options: [{ code: "long", label: "Long sleeve" }] },
];

describe("parseBulkRows", () => {
  it("parses tab-separated spreadsheet rows, keeping numbers as strings and mapping option labels to codes", () => {
    const { rows, errors } = parseBulkRows("M\t2\tSilva\t07\tLong sleeve\nsmall,1,Perera,10,long", sizes, fields);
    expect(errors).toEqual([]);
    expect(rows).toEqual([
      { size_id: 2, quantity: 2, player_name: "Silva", number: "07", values: { sleeve_type: "long" } },
      { size_id: 1, quantity: 1, player_name: "Perera", number: "10", values: { sleeve_type: "long" } },
    ]);
  });

  it("reports unknown sizes and skips blank lines", () => {
    const { rows, errors } = parseBulkRows("\nXXL\t1\n", sizes, fields);
    expect(rows).toHaveLength(1);
    expect(errors).toEqual(['Line 1: unknown size "XXL".']);
  });
});
