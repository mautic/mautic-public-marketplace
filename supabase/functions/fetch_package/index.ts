import { getSupabaseClient } from './supabase_client.ts';
import { main as updateAllowlist } from './isReviewed.ts';

async function fetchPackagistData(packageName: string) {
  const response = await fetch(`https://packagist.org/packages/${packageName}.json`);
  if (!response.ok) {
    throw new Error(`Error fetching data from Packagist: ${response.statusText}`);
  }
  const data = await response.json();
  return data;
}

function validateVersion(version: any): string | null {
  if (!version || version.type !== 'mautic-resource') return null;

  const errors: string[] = [];

  if (!version.description || typeof version.description !== 'string' || version.description.trim() === '') {
    errors.push("Missing required field: description");
  } else if (version.description.length > 300) {
    errors.push("description exceeds 300 characters");
  }

  if (!version.license) errors.push("Missing required field: license");

  const extra = version.extra;
  if (!extra) {
    errors.push("Missing required extra fields: display_name, detailed_description, expected_outcomes");
  } else {
    if (!extra.display_name || typeof extra.display_name !== 'string' || extra.display_name.trim() === '') {
      errors.push("Missing required field: extra.display_name");
    } else if (extra.display_name.length > 150) {
      errors.push("extra.display_name exceeds 150 characters");
    }

    if (!extra.detailed_description || typeof extra.detailed_description !== 'string' || extra.detailed_description.trim() === '') {
      errors.push("Missing required field: extra.detailed_description");
    } else if (extra.detailed_description.length > 5000) {
      errors.push("extra.detailed_description exceeds 5000 characters");
    }

    if (!extra.expected_outcomes || typeof extra.expected_outcomes !== 'string' || extra.expected_outcomes.trim() === '') {
      errors.push("Missing required field: extra.expected_outcomes");
    } else if (extra.expected_outcomes.length > 2000) {
      errors.push("extra.expected_outcomes exceeds 2000 characters");
    }

    if (extra.industry && !Array.isArray(extra.industry)) {
      errors.push("extra.industry must be an array");
    }
  }

  return errors.length > 0 ? errors.join("; ") : null;
}

async function storeInSupabase(supabaseClient: any, packageData: any) {
  if (!packageData || !packageData.package) {
    console.error('Invalid package data structure:', packageData);
    throw new Error('Invalid package data structure.');
  }

  const { name, description, time, maintainers, type, repository, github_stars, github_watchers, github_forks, github_open_issues, language, dependents, suggesters, downloads, favers, versions } = packageData.package;

  if (!versions) {
    console.error('No versions found for package:', name);
    return;
  }

  // Filter out versions without the required dependency
  const validVersions = Object.entries(versions).filter(([_, version]) => version.require && version.require['mautic/core-lib']);

  if (validVersions.length === 0) {
    console.error('No valid versions found with mautic/core-lib dependency for package:', name);
    return;
  }

  // Insert into packages table first
  const { data: packageDataResponse, error: packageError } = await supabaseClient
    .from('packages')
    .upsert([{
      name,
      description,
      time,
      maintainers,
      type,
      repository,
      github_stars,
      github_watchers,
      github_forks,
      github_open_issues,
      language,
      dependents,
      suggesters,
      downloads,
      favers,
    }], { onConflict: 'name' });

  if (packageError) {
    console.error('Error inserting package data:', packageError);
    return;
  }

  // Now insert into versions table with per-version validation
  for (const [versionKey, version] of validVersions) {
    const smv = version.require['mautic/core-lib'];

    let storedversions: string[] = [];
    const constraints = smv.split('|');
    for (const constraint of constraints) {
      if (constraint.startsWith('^')) {
        const [major, minor] = constraint.substring(1).split('.');
        const patchVersions = [];
        for (let patch = 0; patch <= 20; patch++) {
          patchVersions.push(`${major}.${minor}.${patch}`);
        }
        storedversions.push(...patchVersions);
      } else {
        storedversions.push(constraint);
      }
    }

    const { description, keywords, homepage, version: ver, version_normalized, license, authors, source, dist, type, support, funding, time, extra } = version as any;

    // Validate this specific version
    const validationErrors = validateVersion(version);

    const { data: versionDataResponse, error: versionError } = await supabaseClient
      .from('versions')
      .upsert([{
        package_name: name,
        description,
        keywords,
        homepage,
        version: ver,
        version_normalized,
        license,
        authors,
        source,
        dist,
        type,
        support,
        funding,
        time,
        extra,
        require: version.require,
        smv,
        storedversions,
        validation_errors: validationErrors,
      }], { onConflict: ['package_name', 'version'] });

    if (versionError) {
      console.error('Error inserting version data:', versionError);
    } else {
      console.log('Version data inserted successfully:', versionDataResponse);
    }
  }
}

async function fetchPackages(url: string, supabaseClient: any) {
  try {
    const packageList = await fetch(url);
    if (!packageList.ok) {
      throw new Error(`Error fetching package list: ${packageList.statusText}`);
    }
    const packageNames = await packageList.json();
    for (const packageName of packageNames.packageNames) {
      const packageData = await fetchPackagistData(packageName);
      await storeInSupabase(supabaseClient, packageData);
    }
  } catch (error) {
    console.error('Error:', error);
  }
}

async function main(req: Request) {
  const authHeader = req.headers.get('Authorization');

  console.log('Authorization Header:', authHeader);

  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return new Response('Unauthorized', { status: 401 });
  }

  const token = authHeader.replace('Bearer ', '');

  const supabaseClient = getSupabaseClient(token);

  const validTypes = ["mautic-plugin", "mautic-theme", "mautic-resource"];
  const requestUrl = new URL(req.url);
  let typeParam = requestUrl.searchParams.get("type");

  if (!typeParam) {
    const contentType = req.headers.get("content-type") || "";
    if (contentType.toLowerCase().startsWith("application/json")) {
      let body;
      try {
        body = await req.json();
      } catch {
        return new Response(
          JSON.stringify({ error: "Malformed JSON body" }),
          { status: 400, headers: { "Content-Type": "application/json" } },
        );
      }
      if (body && typeof body.type === "string") {
        typeParam = body.type;
      }
    }
  }

  let types: string[];
  if (typeParam) {
    if (!validTypes.includes(typeParam)) {
      return new Response(
        JSON.stringify({ error: "Invalid type parameter", allowedTypes: validTypes }),
        { status: 400, headers: { "Content-Type": "application/json" } },
      );
    }
    types = [typeParam];
  } else {
    types = validTypes;
  }

  for (const t of types) {
    await fetchPackages(`https://packagist.org/packages/list.json?type=${t}`, supabaseClient);
  }

  if (!typeParam) {
    await updateAllowlist();
  }

  return new Response('Success', { status: 200 });
}

if (import.meta.main) {
  Deno.serve(main);
}

export { fetchPackagistData, storeInSupabase };
