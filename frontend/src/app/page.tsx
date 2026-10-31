"use client";

import { useEffect, useState } from "react";

const apiBase = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost/api";

export default function Home() {
  const [apiStatus, setApiStatus] = useState("Checking…");

  useEffect(() => {
    fetch(`${apiBase}/health`)
      .then((response) => {
        if (!response.ok) throw new Error("API unavailable");
        return response.json();
      })
      .then((data: { status: string }) => setApiStatus(data.status === "ok" ? "Connected" : "Unavailable"))
      .catch(() => setApiStatus("Unavailable"));
  }, []);

  return (
    <main>
      <h1>maxfractal.tv</h1>
      <p>Application scaffold</p>
      <p>API: <span>{apiStatus}</span></p>
    </main>
  );
}
