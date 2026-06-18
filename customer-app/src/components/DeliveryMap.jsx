import { useEffect, useRef } from 'react';
import { loadGoogleMaps } from '../utils/googleMaps';

export default function DeliveryMap({
  latitude,
  longitude,
  onLocationChange,
  className = ''
}) {
  const containerRef = useRef(null);
  const mapRef = useRef(null);
  const markerRef = useRef(null);
  const onLocationChangeRef = useRef(onLocationChange);

  useEffect(() => {
    onLocationChangeRef.current = onLocationChange;
  }, [onLocationChange]);

  useEffect(() => {
    if (!latitude || !longitude) {
      return undefined;
    }

    let cancelled = false;

    (async () => {
      try {
        await loadGoogleMaps();
      } catch {
        return;
      }

      if (cancelled || !containerRef.current) {
        return;
      }

      const lat = Number(latitude);
      const lng = Number(longitude);
      const center = { lat, lng };

      if (!mapRef.current) {
        mapRef.current = new google.maps.Map(containerRef.current, {
          center,
          zoom: 17,
          mapTypeControl: false,
          streetViewControl: false,
          fullscreenControl: false,
          gestureHandling: 'greedy'
        });

        markerRef.current = new google.maps.Marker({
          map: mapRef.current,
          position: center,
          draggable: true
        });

        markerRef.current.addListener('dragend', () => {
          const position = markerRef.current?.getPosition();

          if (!position) {
            return;
          }

          onLocationChangeRef.current?.({
            latitude: position.lat(),
            longitude: position.lng()
          });
        });
      } else {
        mapRef.current.setCenter(center);
        markerRef.current?.setPosition(center);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [latitude, longitude]);

  return (
    <div className={`overflow-hidden rounded-xl border border-slate-200 bg-white ${className}`}>
      <div ref={containerRef} className="h-44 w-full" />
      <p className="px-3 py-2 text-[11px] font-semibold text-slate-500 border-t border-slate-100">
        Arraste o pin para ajustar o ponto de entrega.
      </p>
    </div>
  );
}
