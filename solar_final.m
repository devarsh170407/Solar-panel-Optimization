clc; clear; close all;

%% prompt the user to enter the name of the city they are interested in.
cityName = input('Enter city name: ', 's');

% Get Geographical Coordinates (from openstreetmap API)
geoAPI = sprintf('https://nominatim.openstreetmap.org/search?q=%s&format=json&limit=1', strrep(cityName, ' ', '%20'));
options = weboptions('Timeout', 20); % Increase timeout to 20 seconds
geoData = webread(geoAPI, options);

if isempty(geoData)
    error('City not found! Please check the name and try again.');
end

format long g;  
latitude = str2num(geoData(1).lat); 
longitude = str2num(geoData(1).lon);


fprintf('\n📍 Location Found: %s (Lat: %.4f, Lon: %.4f)\n', cityName, latitude, longitude);
%%
% 
%   for x = 1:10
%       disp(x)
%   end
% 

%% Fetch Past Solar Radiation Data
% 🔽 Simplified Month Selection
fprintf('\n📅 Historical Data Selection:\n');
numMonths = input('Enter how many months of past data you want (1-12): ');

% Validate input
if isempty(numMonths) || numMonths < 1 || numMonths > 12
    numMonths = 3; % Default to 3 months
    fprintf('Invalid input. Defaulting to 3 months.\n');
end

% Calculate Start and End Dates automatically
% NASA data usually has a 2-3 day lag for recent solar parameters
endDateObj = datetime('today', 'TimeZone', 'local') - days(3); 
startDateObj = endDateObj - calmonths(numMonths);

start_date = datestr(startDateObj, 'yyyymmdd');
end_date = datestr(endDateObj, 'yyyymmdd');

fprintf('Retrieving data from %s to %s...\n', datestr(startDateObj, 'dd-mmm-yyyy'), datestr(endDateObj, 'dd-mmm-yyyy'));

% 🌐 NASA POWER API URL
api_url = sprintf(['https://power.larc.nasa.gov/api/temporal/daily/point?' ...
    'parameters=ALLSKY_SFC_SW_DWN&community=RE&longitude=%f&latitude=%f' ...
    '&start=%s&end=%s&format=JSON'], ...
    longitude, latitude, start_date, end_date);

options = weboptions('Timeout', 20);
response = webread(api_url, options);
solarRadiation = response.properties.parameter.ALLSKY_SFC_SW_DWN;
dates = fieldnames(solarRadiation);


dateArray = datetime(erase(dates, 'x'), 'InputFormat', 'yyyyMMdd', 'Format', 'yyyy-MM-dd');


solarValues = zeros(size(dates));
for i = 1:numel(dates)
    solarValues(i) = solarRadiation.(dates{i});
end

% 🔽 CLEAN DATA (Remove invalid -999 or negative values)
validIdx = solarValues > 0;
solarValues = solarValues(validIdx);
dateArray = dateArray(validIdx);

if isempty(solarValues)
    error('No valid solar data found for this period. Try a different range.');
end

%% Plot Solar Radiation Data
figure;
plot(dateArray, solarValues, '-o', 'LineWidth', 2);
xlabel('Date');
ylabel('Solar Radiation (kWh/m²/day)');
title(['Past Solar Radiation Data for ', cityName, ' (NASA POWER API)']);
grid on;

%% Shading Analysis
solarValues = solarValues / max(solarValues); 
shadeThreshold = 0.3;
shadedDays = dateArray(solarValues < shadeThreshold);

if ~isempty(shadedDays)
    fprintf('\n⚠️ Warning: Shading detected on these days:\n');
    disp(shadedDays);
else
    fprintf('\n✅ No major shading issues detected!\n');
end

%% Plot Shading Effects
figure;
bar(dateArray, solarValues, 'FaceColor', 'b');
hold on;
bar(shadedDays, solarValues(solarValues < shadeThreshold), 'FaceColor', 'r');
xlabel('Date');
ylabel('Normalized Solar Radiation');
title(['Past Shading Analysis for ', cityName, ' (Red = Shaded Days)']);

grid on;

%% Ask User for Corrected Coordinates
web('https://www.google.com/maps', '-browser');
currentLat = str2num(input('Enter your implementation place latitude: ', 's')); 
currentLon = str2num(input('Enter your implementation place longitude: ', 's'));

fprintf('\n📍 Using Selected Location: Lat: %.15f, Lon: %.15f\n', currentLat, currentLon);

%% Ask for Implementation Date
fprintf('\n📅 Choose Simulation Date:\n');
fprintf('(Note: NASA data usually has a 2-3 day lag. Use a past date for tracking results.)\n');
implDate = input('Enter the date of implementation (YYYYMMDD): ', 's');

if isempty(implDate)
    implDate = end_date; % Default to last valid day
    fprintf('Defaulting to %s\n', implDate);
end

%% Fetch Solar Radiation Data from NASA POWER API
api_url = sprintf(['https://power.larc.nasa.gov/api/temporal/daily/point?' ...
    'parameters=ALLSKY_SFC_SW_DWN&community=RE&longitude=%f&latitude=%f&start=%s&end=%s&format=JSON'], ...
    longitude, latitude, implDate, implDate);

options = weboptions('Timeout', 20);
response = webread(api_url, options);
solarRadiation = response.properties.parameter.ALLSKY_SFC_SW_DWN;
dates = fieldnames(solarRadiation);
dateArray = datetime(erase(dates, "x"), 'InputFormat', 'yyyyMMdd');
solarValues = cellfun(@(d) solarRadiation.(d), dates);

%% Normalize Solar Radiation
solarValues = solarValues / max(solarValues);
shadeThreshold = 0.3;
shadedDays = dateArray(solarValues < shadeThreshold);

%% Predict Best Location Using Machine Learning (Basic Heuristic)
averageRadiation = mean(solarValues);
if averageRadiation > 0.2
    fprintf('\n✅ The selected location is suitable for solar panel implementation!\n');
else
    fprintf('\n⚠️ Warning: The selected location may not be optimal for solar panel implementation. Consider alternative locations.\n');
end

%% Advanced Shading Analysis - Dynamic Sun Angles
sunElevation = linspace(0, 90, length(dateArray)); 
obstacleHeight = 10; % Assume a 10m obstacle
obstacleDistance = 20; % 20m away
shadingThreshold = atand(obstacleHeight / obstacleDistance);
shadedTimes = sunElevation < shadingThreshold;

%% Ask User for Required Energy Output
requiredEnergy = input('Enter the required energy output in kWh: ');

%% Ask User if Rotation is Needed
useRotation = input('Do you want rotatable solar panels? (y/n): ', 's');
solarTime = []; 
optimalRotationAngles = []; 
efficiencyFactor = 1.0;  


%% Define Solar Panel Efficiency and Area
panelEfficiency = 0.2;
panelArea = 1.6;


%% Calculate Required Number of Solar Panels
energyPerPanel = sum(solarValues) * panelEfficiency * panelArea * efficiencyFactor;
numPanels = ceil(requiredEnergy / energyPerPanel);

fprintf('\n🔋 Estimated Number of Solar Panels Required: %d\n', numPanels);
fprintf('\n⚡ Estimated Energy Output per Panel: %.2f kWh/day\n', energyPerPanel);
fprintf('\n⚡ Total Estimated Energy Output: %.2f kWh/day\n', numPanels * energyPerPanel);

if strcmpi(useRotation, 'y')  
    efficiencyFactor = 1.25; 
    fprintf('\n✅ Rotatable Solar Panels Selected! Higher energy generation.\n');

    
    solarTime = linspace(6, 18, 50); % Hourly intervals from 6 AM to 6 PM

    % Date processing
    N = day(datetime(implDate, 'InputFormat', 'yyyyMMdd'), 'dayofyear');
    declination = 23.45 * sind(360 * (N - 81) / 365); 

    % Pre-allocate arrays
    elevationAngles = zeros(size(solarTime));
    azimuthAngles = zeros(size(solarTime));

    fprintf('\n✅ Hemispherical (Dual-Axis) Tracking Coordinates Calculated!\n');
    fprintf('------------------------------------------------------------\n');
    fprintf('%-10s | %-15s | %-15s\n', 'Time (hr)', 'Elevation (°)', 'Azimuth (°)');
    fprintf('------------------------------------------------------------\n');

    for i = 1:length(solarTime)
        hourAngle = (solarTime(i) - 12) * 15; % 15 degrees per hour
        
        % Solar Elevation Angle (Altitude)
        elevationAngles(i) = asind(sind(latitude) * sind(declination) + ...
                                   cosd(latitude) * cosd(declination) * cosd(hourAngle));
        
        % Avoid invalid elevation (sun below horizon)
        if elevationAngles(i) < 0, elevationAngles(i) = 0; end

        % Solar Azimuth Angle
        % Formula using cosine rule for spherical triangles
        cosAzimuth = (sind(declination) - sind(elevationAngles(i)) * sind(latitude)) / ...
                     (cosd(elevationAngles(i)) * cosd(latitude));
        
        % Clip for precision issues
        cosAzimuth = max(min(cosAzimuth, 1), -1);
        azAngle = acosd(cosAzimuth);

        % Adjust azimuth based on time (Morning vs Afternoon)
        if hourAngle < 0
            azimuthAngles(i) = azAngle; % Morning (East of South)
        else
            azimuthAngles(i) = 360 - azAngle; % Afternoon (West of South)
        end

        fprintf('%-10.2f | %-15.2f | %-15.2f\n', solarTime(i), elevationAngles(i), azimuthAngles(i));
    end

    % Optimal Tilt (Max Elevation of the day)
    [maxElevation, maxIdx] = max(elevationAngles);
    fprintf('\n📐 Maximum Optimal Elevation: %.2f° at %.2f Hours\n', maxElevation, solarTime(maxIdx));
    fprintf('🧭 General Orientation: South-facing (180°) for Northern Hemisphere.\n');
    
    % Visualization: Hemispherical Solar Path
    figure;
    subplot(1,2,1);
    plot(solarTime, elevationAngles, '-o', 'Color', [0.85 0.33 0.1], 'LineWidth', 2);
    xlabel('Time (Hours)');
    ylabel('Elevation Angle (Degrees)');
    title('Solar Elevation (Altitude)');
    grid on;

    subplot(1,2,2);
    plot(solarTime, azimuthAngles, '-s', 'Color', [0 0.45 0.74], 'LineWidth', 2);
    xlabel('Time (Hours)');
    ylabel('Azimuth Angle (Degrees)');
    title('Solar Azimuth');
    grid on;

    % Static 3D Solar Path Visualization (Persists after script)
    figure('Name', 'Static 3D Solar Path', 'NumberTitle', 'off', 'Color', 'w');
    [xPathStatic, yPathStatic, zPathStatic] = sph2cart(deg2rad(90 - azimuthAngles), deg2rad(elevationAngles), 1);
    
    plot3(xPathStatic, yPathStatic, zPathStatic, 'r-o', 'LineWidth', 3, 'MarkerFaceColor', 'y');
    hold on;
    % Draw a simple hemisphere grid
    [px, py, pz] = sphere(30);
    pz(pz < 0) = 0; % Keep only the top half
    mesh(px, py, pz, 'FaceAlpha', 0.05, 'EdgeAlpha', 0.1, 'HandleVisibility', 'off');
    
    % Add compass labels
    text(0, 1.1, 0, 'N', 'HorizontalAlignment', 'center', 'FontWeight', 'bold', 'Color', [0, 0.5, 0]);
    text(0, -1.1, 0, 'S', 'HorizontalAlignment', 'center', 'FontWeight', 'bold', 'Color', 'red');
    text(1.1, 0, 0, 'E', 'HorizontalAlignment', 'center', 'FontWeight', 'bold', 'Color', 'blue');
    text(-1.1, 0, 0, 'W', 'HorizontalAlignment', 'center', 'FontWeight', 'bold', 'Color', [1, 0.5, 0]);
    
    xlabel('West - East'); ylabel('South - North'); zlabel('Vertical');
    title(['Static Solar Path - ', cityName]);
    axis equal; grid on;
    view([-45, 30]);

    % Enhanced 3D Animated Solar Tracking Visualization
    fig = figure('Name', '3D Solar Tracking Animation', 'NumberTitle', 'off', 'Color', 'w');
    set(fig, 'Position', [100, 100, 800, 600]);
    
    % Convert all solar angles to Cartesian path for reference
    [xPath, yPath, zPath] = sph2cart(deg2rad(90 - azimuthAngles), deg2rad(elevationAngles), 10);
    
    % Initialize 3D Plot
    hold on; grid on; axis equal;
    view([-45, 30]);
    xlabel('West - East'); ylabel('South - North'); zlabel('Altitude');
    title(['Solar Panel Tracking Simulation for ', cityName], 'FontSize', 14);
    xlim([-12 12]); ylim([-12 12]); zlim([0 12]);
    
    % 1. Draw Ground Plane
    [gx, gy] = meshgrid(-12:2:12, -12:2:12);
    gz = zeros(size(gx));
    mesh(gx, gy, gz, 'EdgeColor', [0.8 0.8 0.8], 'FaceAlpha', 0);
    
    % 2. Draw Hemispherical Sky Dome
    [sx, sy, sz] = sphere(30);
    sx = sx * 10; sy = sy * 10; sz = sz * 10;
    sz(sz < 0) = 0;
    mesh(sx, sy, sz, 'FaceAlpha', 0.03, 'EdgeAlpha', 0.1, 'HandleVisibility', 'off');
    
    % 3. Plot Solar Path line
    plot3(xPath, yPath, zPath, 'k--', 'LineWidth', 1, 'HandleVisibility', 'off');
    
    % 4. Compass Labels
    text(0, 11, 0, 'N', 'HorizontalAlignment', 'center', 'FontWeight', 'bold', 'Color', [0, 0.5, 0]); % Dark Green
    text(0, -11, 0, 'S', 'HorizontalAlignment', 'center', 'FontWeight', 'bold', 'Color', 'red');
    text(11, 0, 0, 'E', 'HorizontalAlignment', 'center', 'FontWeight', 'bold', 'Color', 'blue');
    text(-11, 0, 0, 'W', 'HorizontalAlignment', 'center', 'FontWeight', 'bold', 'Color', [1, 0.5, 0]); % Orange
    
    % 5. Create Solar Panel (Rectangular Patch)
    % Initial position: horizontal at origin
    pw = 3; ph = 2; % Panel width and height
    panelX = [-pw/2, pw/2, pw/2, -pw/2];
    panelY = [-ph/2, -ph/2, ph/2, ph/2];
    panelZ = [0, 0, 0, 0];
    hPanel = patch(panelX, panelY, panelZ, 'blue', 'FaceAlpha', 0.8, 'EdgeColor', 'k', 'LineWidth', 2);
    
    % 6. Create Sun Sphere
    [sunX, sunY, sunZ] = sphere(10);
    hSun = surf(sunX*0.5, sunY*0.5, sunZ*0.5, 'FaceColor', 'yellow', 'EdgeColor', 'none');
    hSunLight = light('Position', [0 0 10], 'Style', 'local');
    lighting gouraud;
    
    % 7. Tracking Line (Panel to Sun)
    hBeam = plot3([0 0], [0 0], [0 0], 'y:', 'LineWidth', 1.5);
    
    % Animation Loop (Runs Once)
    fprintf('\n🎬 Starting 3D Tracking Animation...\n');
    timeLabel = text(-10, 10, 11, '', 'FontSize', 12, 'FontWeight', 'bold');
    
    for k = 1:length(solarTime)
        % Check if figure still exists
        if ~ishandle(fig), break; end
        
        % Update Sun Position
        set(hSun, 'XData', sunX*0.5 + xPath(k), 'YData', sunY*0.5 + yPath(k), 'ZData', sunZ*0.5 + zPath(k));
        set(hSunLight, 'Position', [xPath(k) yPath(k) zPath(k)]);
        
        % Update Beam
        set(hBeam, 'XData', [0 xPath(k)], 'YData', [0 yPath(k)], 'ZData', [0 zPath(k)]);
        
        % Calculate Rotation Matrices for the Panel
        az = deg2rad(90 - azimuthAngles(k));
        el = deg2rad(elevationAngles(k));
        
        % Base panel coordinates
        pts = [panelX; panelY; panelZ];
        
        % 1. Rotation for Elevation (around Y' axis)
        R_el = [cos(el) 0 sin(el); 0 1 0; -sin(el) 0 cos(el)];
        % 2. Rotation for Azimuth (around Z axis)
        R_az = [cos(az) -sin(az) 0; sin(az) cos(az) 0; 0 0 1];
        
        % Combined rotation
        rotPts = R_az * R_el * pts;
        
        % Update Panel Patch
        set(hPanel, 'XData', rotPts(1,:), 'YData', rotPts(2,:), 'ZData', rotPts(3,:));
        
        % Update Time Label
        set(timeLabel, 'String', sprintf('Time: %.2f hr', solarTime(k)));
        
        drawnow;
        pause(0.15); % Increased pause for slower, more visible animation
    end
    
    fprintf('✅ Animation Ended.\n');
else
    fprintf('\n🚫 Skipping hemispherical tracking and animation calculations as per user choice.\n');
end

  
  

%% Calculate Optimal Tilt Angle for Maximum Energy Generation
N = day(datetime(start_date, 'InputFormat', 'yyyyMMdd'), 'dayofyear');
declination = 23.45 * sind(360 * (N - 81) / 365); 
optimalTiltAngle = abs(latitude - declination);

fprintf('\n📐 Optimal Tilt Angle for Maximum Energy Generation: %.2f°\n', optimalTiltAngle);


%% Energy Output Estimation
panelEfficiency = 0.2;
panelArea = 1.6; 
energyOutput = sum(solarValues) * panelEfficiency * panelArea;


%% Fetch Elevation Data
fprintf('\nFetching Elevation Data...\n');
elevAPI = sprintf('https://api.opentopodata.org/v1/srtm90m?locations=%.6f,%.6f', currentLat, currentLon);
options = weboptions('Timeout', 20);  
elevData = webread(elevAPI, options);
elevation = elevData.results(1).elevation;

fprintf('\n🗻 Approximate Elevation: %.2f meters\n', elevation);



%% Generate 3D Terrain Model using Open-Elevation API
gridSize = 5;
latitudes = linspace(currentLat - 0.01, currentLat + 0.01, gridSize);
longitudes = linspace(currentLon - 0.01, currentLon + 0.01, gridSize);
[latGrid, lonGrid] = meshgrid(latitudes, longitudes);



elevationData = zeros(gridSize, gridSize);
options = weboptions('Timeout', 20);
for i = 1:gridSize
    for j = 1:gridSize
        api_url = sprintf('https://api.open-elevation.com/api/v1/lookup?locations=%.6f,%.6f', latGrid(i, j), lonGrid(i, j));
        elevResponse = webread(api_url, options);
        elevationData(i, j) = elevResponse.results(1).elevation;
    end
end

%% Plot 3D Terrain Model
figure;
surf(lonGrid, latGrid, elevationData, 'EdgeColor', 'none');
colormap jet;
shading interp;
colorbar;
xlabel('Longitude'); ylabel('Latitude'); zlabel('Elevation (m)');
title(['3D Terrain Model for ', cityName]);
grid on;
view(3);

fprintf('\n✅ 3D Terrain Model Generated Successfully!\n');

%% Generate KML File for Google Earth
kmlFile = 'SolarPanel_3D_Map.kml';
fid = fopen(kmlFile, 'w');

fprintf(fid, ['<?xml version="1.0" encoding="UTF-8"?>\n' ...
    '<kml xmlns="http://www.opengis.net/kml/2.2">\n<Document>\n' ...
    '<Placemark><name>Solar Panel</name>\n' ...
    '<Point><coordinates>%.6f,%.6f,%.2f</coordinates></Point>\n' ...
    '</Placemark>\n</Document>\n</kml>\n'], currentLon, currentLat, elevation);

fclose(fid);
