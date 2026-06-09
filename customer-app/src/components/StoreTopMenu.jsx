import React, { useState, useEffect } from 'react';
import StoreAboutModal from './StoreAboutModal';
import {
  Home,
  ReceiptText,
  Settings,
  LogOut,
  User,
  MapPin,
  Bike,
  X,
  CheckCircle,
  Clock3,
  Info
} from 'lucide-react';

const formatTime = (value) => {
  if (!value) return null;
  return String(value).slice(0, 5);
};

const getTodayScheduleLabel = (store) => {
  const schedule = store?.business_hours;