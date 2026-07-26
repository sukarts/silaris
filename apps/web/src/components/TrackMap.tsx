"use client";

import { useEffect, useRef } from "react";
import type { Map as LeafletMap } from "leaflet";
import "leaflet/dist/leaflet.css";

export interface MapPoint {
  latitude: number | string;
  longitude: number | string;
  label?: string | null;
  reached?: boolean;
}

/**
 * Carte de suivi (Leaflet + fond OpenStreetMap, sans clé d'API).
 *
 * Leaflet manipule le DOM directement : le module est chargé à l'exécution
 * côté navigateur uniquement (il référence `window` à l'import), et les
 * marqueurs sont dessinés en SVG plutôt qu'avec les icônes par défaut, dont
 * les URL d'images cassent une fois le bundle servi.
 */
export function TrackMap({
  trail = [],
  stops = [],
  vehicle,
  height = 320,
}: {
  trail?: MapPoint[];
  stops?: MapPoint[];
  vehicle?: MapPoint | null;
  height?: number;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<LeafletMap | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function draw() {
      const L = (await import("leaflet")).default;
      if (cancelled || !containerRef.current) return;

      const num = (point: MapPoint) => [Number(point.latitude), Number(point.longitude)] as [number, number];
      const path = trail.map(num);
      const stopPoints = stops.filter((s) => s.latitude != null && s.longitude != null);
      const vehiclePoint = vehicle ? num(vehicle) : null;
      const all = [...path, ...stopPoints.map(num), ...(vehiclePoint ? [vehiclePoint] : [])];
      if (all.length === 0) return;

      mapRef.current?.remove();
      const map = L.map(containerRef.current, { zoomControl: true, attributionControl: true });
      mapRef.current = map;

      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      }).addTo(map);

      if (path.length > 1) {
        L.polyline(path, { color: "#3d7fa6", weight: 3, opacity: 0.8 }).addTo(map);
      }

      for (const stop of stopPoints) {
        const reached = stop.reached === true;
        L.circleMarker(num(stop), {
          radius: 7,
          color: reached ? "#2e9e6b" : "#8a93a3",
          fillColor: reached ? "#2e9e6b" : "#ffffff",
          fillOpacity: 1,
          weight: 3,
        })
          .addTo(map)
          .bindTooltip(`${stop.label ?? "Arrêt"}${reached ? " — atteint" : ""}`, { direction: "top" });
      }

      if (vehiclePoint) {
        L.circleMarker(vehiclePoint, {
          radius: 9,
          color: "#e8663d",
          fillColor: "#e8663d",
          fillOpacity: 0.9,
          weight: 3,
        })
          .addTo(map)
          .bindTooltip(vehicle?.label ?? "Véhicule", { direction: "top", permanent: false });
      }

      const bounds = L.latLngBounds(all);
      map.fitBounds(bounds, { padding: [28, 28], maxZoom: 14 });
    }

    void draw();

    return () => {
      cancelled = true;
      mapRef.current?.remove();
      mapRef.current = null;
    };
  }, [trail, stops, vehicle]);

  return <div ref={containerRef} style={{ height }} className="w-full overflow-hidden rounded-xl border border-line" />;
}
