export const formatDistrictLabel = (district, city) => {
  return [district, city].filter(Boolean).join(', ');
};

export const formatSavedAddressSummary = ({ address, district, city, address_complement }) => {
  const lines = [address, formatDistrictLabel(district, city), address_complement].filter(Boolean);
  return lines;
};
