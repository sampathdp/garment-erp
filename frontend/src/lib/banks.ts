/** Major licensed commercial and specialized banks in Sri Lanka. */
export const SRI_LANKA_BANKS = [
  "Bank of Ceylon (BOC)",
  "People's Bank",
  "Commercial Bank of Ceylon",
  "Hatton National Bank (HNB)",
  "Sampath Bank",
  "National Savings Bank (NSB)",
  "Seylan Bank",
  "Nations Trust Bank (NTB)",
  "DFCC Bank",
  "Pan Asia Bank",
  "Union Bank of Colombo",
  "Amana Bank",
  "Cargills Bank",
  "Standard Chartered Bank",
  "HSBC Sri Lanka",
  "Regional Development Bank (RDB)",
  "Sanasa Development Bank (SDB)",
  "State Mortgage & Investment Bank (SMIB)",
  "Habib Bank",
  "MCB Bank",
  "Other Bank",
] as const;

export type SriLankaBank = (typeof SRI_LANKA_BANKS)[number];
