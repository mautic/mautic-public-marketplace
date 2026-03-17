import { getSupabaseClient } from './supabase_client.ts';
import { fetchPackagistData, storeInSupabase } from './index.ts';
import { main as updateAllowlist } from './isReviewed.ts';

const token = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") || "";
const supabaseClient = getSupabaseClient(token);

const urls = [
  "https://packagist.org/packages/list.json?type=mautic-plugin",
  "https://packagist.org/packages/list.json?type=mautic-theme",
  "https://packagist.org/packages/list.json?type=mautic-resource",
];

for (const url of urls) {
  try {
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`Error fetching package list: ${response.statusText}`);
    }
    const { packageNames } = await response.json();
    for (const packageName of packageNames) {
      const packageData = await fetchPackagistData(packageName);
      await storeInSupabase(supabaseClient, packageData);
    }
  } catch (error) {
    console.error('Error:', error);
  }
}

await updateAllowlist();
console.log('Seeding complete.');
