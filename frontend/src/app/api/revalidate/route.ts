import { NextRequest, NextResponse } from "next/server";
import { revalidatePath } from "next/cache";

export async function GET(request: NextRequest) {
  return handleRevalidate(request);
}

export async function POST(request: NextRequest) {
  return handleRevalidate(request);
}

async function handleRevalidate(request: NextRequest) {
  try {
    const { searchParams } = new URL(request.url);
    let secret = searchParams.get("secret");
    let path = searchParams.get("path");

    // Fallback to JSON body if POST and values are not in searchParams
    if (request.method === "POST") {
      try {
        const body = await request.json();
        if (!secret && body?.secret) {
          secret = body.secret;
        }
        if (!path && body?.path) {
          path = body.path;
        }
      } catch {
        // Ignore JSON parsing errors
      }
    }

    const configuredSecret = process.env.REVALIDATION_SECRET;
    if (!configuredSecret) {
      return NextResponse.json(
        { error: "Revalidation secret is not configured on the server." },
        { status: 500 }
      );
    }

    if (!secret || secret !== configuredSecret) {
      return NextResponse.json({ error: "Invalid secret token." }, { status: 401 });
    }

    if (!path) {
      return NextResponse.json({ error: "Missing path parameter." }, { status: 400 });
    }

    // Ensure path starts with a slash
    const formattedPath = path.startsWith("/") ? path : `/${path}`;

    revalidatePath(formattedPath);

    return NextResponse.json({
      revalidated: true,
      path: formattedPath,
      timestamp: new Date().toISOString()
    });
  } catch (err) {
    const errorMessage = err instanceof Error ? err.message : "Internal Server Error";
    return NextResponse.json(
      { error: errorMessage },
      { status: 500 }
    );
  }
}
