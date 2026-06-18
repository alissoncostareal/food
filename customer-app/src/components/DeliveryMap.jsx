import { useEffect, useRef } from 'react';
import { loadGoogleMaps } from '../utils/googleMaps';

export default function DeliveryMap({
  centerLat,
  centerLng,
  markerLat = null,
  markerLng = null,
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
    if (centerLat == null || centerLng == null) {
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

      const center = { lat: Number(centerLat), lng: Number(centerLng) };
      const hasMarker = markerLat != null && markerLng != null;
      const markerPosition = hasMarker
        ? { lat: Number(markerLat), lng: Number(markerLng) }
        : null;

      if (!mapRef.current) {
        mapRef.current = new google.maps.Map(containerRef.current, {
          center: markerPosition || center,
          zoom: hasMarker ? 17 : 13,
          mapTypeControl: false,
          streetViewControl: false,
          fullscreenControl: false,
          gestureHandling: 'greedy'
        });

        markerRef.current = new google.maps.Marker({
          map: mapRef.current,
          position: markerPosition || center,
          draggable: Boolean(onLocationChangeRef.current),
          visible: hasMarker,
          animation: hasMarker ? google.maps.Animation.DROP : null
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

        window.setTimeout(() => {
          if (mapRef.current) {
            google.maps.event.trigger(mapRef.current, 'resize');
            mapRef.current.setCenter(markerPosition || center);
          }
        }, 250);
      } else {
        if (hasMarker) {
          mapRef.current.setCenter(markerPosition);
          mapRef.current.setZoom(17);
          markerRef.current.setPosition(markerPosition);
          markerRef.current.setVisible(true);
          markerRef.current.setDraggable(Boolean(onLocationChangeRef.current));
        } else {
          mapRef.current.setCenter(center);
          mapRef.current.setZoom(13);
          markerRef.current.setVisible(false);
        }

        google.maps.event.trigger(mapRef.current, 'resize');
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [centerLat, centerLng, markerLat, markerLng]);

  return (
    <div className={`overflow-hidden rounded-xl border border-slate-200 bg-white ${className}`}>
      <div ref={containerRef} className="h-56 w-full sm:h-64 bg-slate-100" />
      <p className="px-3 py-2 text-[11px] font-semibold text-slate-500 border-t border-slate-100">
        {markerLat != null && markerLng != null
          ? 'Confirme o ponto no mapa. Arraste o pin se precisar ajustar.'
          : 'Escolha uma sugestão do Google Maps na lista ao digitar o endereço.'}
      </p>
    </div>
  );
}
