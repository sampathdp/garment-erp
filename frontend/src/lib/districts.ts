/** The 25 administrative districts of Sri Lanka. */
export const SRI_LANKA_DISTRICTS = [
  "Ampara",
  "Anuradhapura",
  "Badulla",
  "Batticaloa",
  "Colombo",
  "Galle",
  "Gampaha",
  "Hambantota",
  "Jaffna",
  "Kalutara",
  "Kandy",
  "Kegalle",
  "Kilinochchi",
  "Kurunegala",
  "Mannar",
  "Matale",
  "Matara",
  "Monaragala",
  "Mullaitivu",
  "Nuwara Eliya",
  "Polonnaruwa",
  "Puttalam",
  "Ratnapura",
  "Trincomalee",
  "Vavuniya",
] as const;

export type SriLankaDistrict = (typeof SRI_LANKA_DISTRICTS)[number];

/** Main towns per district, offered as type-to-search suggestions. Free text is still allowed. */
export const SRI_LANKA_TOWNS: Record<SriLankaDistrict, readonly string[]> = {
  Ampara: ["Ampara", "Akkaraipattu", "Kalmunai", "Sammanthurai", "Pottuvil", "Dehiattakandiya", "Uhana", "Mahaoya", "Nintavur"],
  Anuradhapura: ["Anuradhapura", "Kekirawa", "Medawachchiya", "Thambuttegama", "Eppawala", "Mihintale", "Galenbindunuwewa", "Nochchiyagama", "Kebithigollewa"],
  Badulla: ["Badulla", "Bandarawela", "Haputale", "Welimada", "Mahiyanganaya", "Hali-Ela", "Passara", "Ella", "Diyatalawa"],
  Batticaloa: ["Batticaloa", "Eravur", "Kattankudy", "Valaichchenai", "Kaluwanchikudy", "Chenkalady"],
  Colombo: ["Colombo", "Dehiwala-Mount Lavinia", "Moratuwa", "Sri Jayawardenepura Kotte", "Maharagama", "Kesbewa", "Homagama", "Kaduwela", "Kolonnawa", "Nugegoda", "Battaramulla", "Piliyandala", "Avissawella", "Padukka", "Boralesgamuwa", "Kottawa", "Malabe", "Wellampitiya"],
  Galle: ["Galle", "Ambalangoda", "Hikkaduwa", "Elpitiya", "Baddegama", "Karapitiya", "Bentota", "Ahangama", "Udugama", "Neluwa", "Batapola"],
  Gampaha: ["Gampaha", "Negombo", "Ja-Ela", "Wattala", "Kadawatha", "Kiribathgoda", "Ragama", "Kelaniya", "Minuwangoda", "Divulapitiya", "Mirigama", "Nittambuwa", "Veyangoda", "Katunayake", "Seeduwa", "Kandana", "Delgoda", "Ganemulla"],
  Hambantota: ["Hambantota", "Tangalle", "Tissamaharama", "Ambalantota", "Beliatta", "Weeraketiya", "Sooriyawewa", "Middeniya", "Walasmulla"],
  Jaffna: ["Jaffna", "Chavakachcheri", "Point Pedro", "Nallur", "Chunnakam", "Kopay", "Valvettithurai", "Kayts", "Manipay"],
  Kalutara: ["Kalutara", "Panadura", "Horana", "Beruwala", "Aluthgama", "Matugama", "Wadduwa", "Bandaragama", "Ingiriya", "Agalawatta", "Bulathsinhala"],
  Kandy: ["Kandy", "Peradeniya", "Katugastota", "Gampola", "Nawalapitiya", "Kundasale", "Digana", "Akurana", "Wattegama", "Kadugannawa", "Pilimathalawa", "Galagedara", "Teldeniya"],
  Kegalle: ["Kegalle", "Mawanella", "Warakapola", "Rambukkana", "Ruwanwella", "Dehiowita", "Deraniyagala", "Yatiyantota", "Galigamuwa", "Aranayake"],
  Kilinochchi: ["Kilinochchi", "Paranthan", "Pallai", "Poonakary"],
  Kurunegala: ["Kurunegala", "Kuliyapitiya", "Pannala", "Narammala", "Wariyapola", "Polgahawela", "Alawwa", "Nikaweratiya", "Mawathagama", "Giriulla", "Ibbagamuwa", "Hettipola", "Galgamuwa", "Maho"],
  Mannar: ["Mannar", "Murunkan", "Madhu", "Nanattan", "Pesalai"],
  Matale: ["Matale", "Dambulla", "Galewela", "Ukuwela", "Rattota", "Naula", "Sigiriya", "Palapathwela"],
  Matara: ["Matara", "Weligama", "Akuressa", "Dikwella", "Hakmana", "Kamburupitiya", "Deniyaya", "Devinuwara", "Mirissa", "Kekanadura"],
  Monaragala: ["Monaragala", "Wellawaya", "Buttala", "Bibile", "Kataragama", "Siyambalanduwa", "Thanamalwila", "Medagama"],
  Mullaitivu: ["Mullaitivu", "Puthukkudiyiruppu", "Oddusuddan", "Mallavi", "Mankulam"],
  "Nuwara Eliya": ["Nuwara Eliya", "Hatton", "Talawakele", "Nanu Oya", "Ginigathhena", "Maskeliya", "Kotagala", "Walapane", "Ragala", "Rikillagaskada"],
  Polonnaruwa: ["Polonnaruwa", "Kaduruwela", "Hingurakgoda", "Medirigiriya", "Aralaganwila", "Manampitiya", "Welikanda"],
  Puttalam: ["Puttalam", "Chilaw", "Wennappuwa", "Marawila", "Nattandiya", "Dankotuwa", "Anamaduwa", "Kalpitiya", "Madampe", "Mundalama"],
  Ratnapura: ["Ratnapura", "Balangoda", "Embilipitiya", "Pelmadulla", "Eheliyagoda", "Kuruwita", "Kahawatta", "Rakwana", "Godakawela", "Kalawana", "Opanayaka"],
  Trincomalee: ["Trincomalee", "Kinniya", "Kantale", "Mutur", "Nilaveli", "Kuchchaveli", "Seruwila"],
  Vavuniya: ["Vavuniya", "Cheddikulam", "Omanthai", "Nedunkeni"],
};

/** Towns for one district, or every town (sorted, de-duplicated) when no district is chosen. */
export function townsFor(district?: string): string[] {
  if (district && district in SRI_LANKA_TOWNS) return [...SRI_LANKA_TOWNS[district as SriLankaDistrict]].sort();
  return [...new Set(Object.values(SRI_LANKA_TOWNS).flat())].sort();
}

/** The district a known town belongs to (case-insensitive), or undefined for a free-text town. */
export function districtOfTown(town: string): SriLankaDistrict | undefined {
  const needle = town.trim().toLowerCase();
  if (!needle) return undefined;
  return SRI_LANKA_DISTRICTS.find((d) => SRI_LANKA_TOWNS[d].some((t) => t.toLowerCase() === needle));
}
