export interface VideoWatermarkData {
  mobile_number: string;
  user_id: number;
  session_id: string;
}

export interface VideoPlaybackSessionData {
  asset_id: string;
  playback_url: string;
  playback_token: string;
  expires_in: number;
  duration_seconds: number;
  resolutions: string[];
  driver: string;
  watermark: VideoWatermarkData;
}

export interface VideoPlaybackAuthResponse {
  message: string;
  lesson_id: number;
  title: string;
  session: VideoPlaybackSessionData;
}
